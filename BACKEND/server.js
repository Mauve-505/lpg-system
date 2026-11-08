require('dotenv').config();
const express = require('express'), session = require('express-session');
const bcrypt = require('bcryptjs'), mysql = require('mysql2/promise');

const db = mysql.createPool({
  host: process.env.DB_HOST || 'localhost', user: process.env.DB_USER || 'root',
  password: process.env.DB_PASS || '', database: process.env.DB_NAME || 'lpg_db', dateStrings: true,
});
const app = express();
app.use(express.json());
app.use(session({
  secret: process.env.SESSION_SECRET || 'change-me', resave: false, saveUninitialized: false,
  cookie: { httpOnly: true, sameSite: 'lax', maxAge: 864e5 },
}));

/* ---------- helpers ---------- */
const q = async (sql, p) => (await db.query(sql, p))[0];
const int = v => Math.trunc(Number(v)) || 0;
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
const bad = (res, m) => res.status(400).json({ error: m });
const h = f => (req, res) => f(req, res).catch(e => { console.error(e); res.status(500).json({ error: 'Server error' }); });
const tx = async fn => {
  const c = await db.getConnection();
  try { await c.beginTransaction(); const r = await fn(c); await c.commit(); return r; }
  catch (e) { await c.rollback(); throw e; } finally { c.release(); }
};
const auth = (req, res, n) => req.session.u ? n() : res.status(401).json({ error: 'Please log in' });
const owner = (req, res, n) => req.session.u.role === 'owner' ? n() : res.status(403).json({ error: 'Owner only' });
const mine = (req, res, n) => req.session.u.role === 'customer' && req.session.u.customer_id ? n() : res.status(403).json({ error: 'Customers only' });

const customers = () => q(`SELECT c.id,c.name,c.contact,c.address,
  (SELECT COALESCE(SUM(i.qty*i.unit_price),0) FROM sales s JOIN sale_items i ON i.sale_id=s.id WHERE s.customer_id=c.id AND s.payment_type='credit')
  - (SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.customer_id=c.id) AS balance,
  (SELECT COALESCE(SUM(i.qty-i.empties_received),0) FROM sales s JOIN sale_items i ON i.sale_id=s.id WHERE s.customer_id=c.id) AS empties_owed
  FROM customers c WHERE c.is_active=1 ORDER BY c.name`);

/* ---------- public: health, login, signup ---------- */
app.get('/api/health', (req, res) => res.json({ ok: true }));
app.post('/api/login', h(async (req, res) => {
  const [u] = await q('SELECT * FROM users WHERE username=? AND is_active=1', [String(req.body.username || '')]);
  if (!u || !(await bcrypt.compare(String(req.body.password || ''), u.password_hash))) return bad(res, 'Wrong username or password');
  req.session.u = { id: u.id, name: u.name, role: u.role, customer_id: u.customer_id };
  res.json(req.session.u);
}));
app.post('/api/logout', (req, res) => req.session.destroy(() => res.json({ ok: 1 })));

// public signup: always creates a customer account, then logs the person in
app.post('/api/register', h(async (req, res) => {
  const name = String(req.body.name || '').trim(), username = String(req.body.username || '').trim();
  const contact = String(req.body.contact || '').trim(), address = String(req.body.address || '').trim();
  const password = String(req.body.password || '');
  if (name.length < 2) return bad(res, 'Please enter your full name');
  if (!/^[A-Za-z0-9_.-]{3,30}$/.test(username)) return bad(res, 'Username must be 3 to 30 characters: letters, numbers, dot, dash or underscore');
  if (!contact || !address) return bad(res, 'Mobile number and address are required for delivery');
  if (password.length < 8) return bad(res, 'Password must be at least 8 characters');
  if ((await q('SELECT id FROM users WHERE username=?', [username])).length) return bad(res, 'That username is already taken');
  const hash = await bcrypt.hash(password, 10);
  try {
    const u = await tx(async c => {
      const [cr] = await c.query('INSERT INTO customers (name,contact,address) VALUES (?,?,?)', [name, contact, address]);
      const [ur] = await c.query("INSERT INTO users (name,username,password_hash,role,customer_id) VALUES (?,?,?,'customer',?)", [name, username, hash, cr.insertId]);
      return { id: ur.insertId, name, role: 'customer', customer_id: cr.insertId };
    });
    req.session.u = u;
    res.json(u);
  } catch (e) {
    if (e.code === 'ER_DUP_ENTRY') return bad(res, 'That username is already taken');
    throw e;
  }
}));

app.use('/api', auth);
app.get('/api/me', (req, res) => res.json(req.session.u));

/* ---------- routes customers can use ---------- */
app.get('/api/sizes', h(async (req, res) => res.json(await q('SELECT id,label,selling_price FROM cylinder_sizes WHERE is_active=1 ORDER BY id'))));
app.get('/api/my/account', mine, h(async (req, res) => res.json((await customers()).find(c => c.id === req.session.u.customer_id) || {})));
app.get('/api/my/orders', mine, h(async (req, res) => res.json(await q(`SELECT o.id,o.order_date,z.label size,o.qty,o.payment_type,o.notes,o.status
  FROM orders o JOIN cylinder_sizes z ON z.id=o.size_id WHERE o.customer_id=? ORDER BY o.id DESC LIMIT 100`, [req.session.u.customer_id]))));
app.post('/api/my/orders', mine, h(async (req, res) => {
  const { size_id, qty, pay, notes } = req.body;
  if (!size_id || int(qty) < 1) return bad(res, 'Pick a size and a quantity of at least 1');
  if (!(await q('SELECT id FROM cylinder_sizes WHERE id=? AND is_active=1', [size_id])).length) return bad(res, 'Unknown size');
  await q('INSERT INTO orders (customer_id,size_id,qty,payment_type,notes,order_date) VALUES (?,?,?,?,?,?)',
    [req.session.u.customer_id, size_id, int(qty), pay === 'credit' ? 'credit' : 'cash', notes || null, today()]);
  res.json({ ok: 1 });
}));
app.post('/api/my/orders/:id/cancel', mine, h(async (req, res) => {
  const r = await q("UPDATE orders SET status='cancelled' WHERE id=? AND customer_id=? AND status='pending'", [req.params.id, req.session.u.customer_id]);
  if (!r.affectedRows) return bad(res, 'Only pending orders can be cancelled');
  res.json({ ok: 1 });
}));

/* ---------- everything below is staff and owner only ---------- */
app.use('/api', (req, res, next) => req.session.u.role === 'customer' ? res.status(403).json({ error: 'Not allowed' }) : next());

/* dashboard */
app.get('/api/dashboard', h(async (req, res) => {
  const stock = await q(`SELECT z.id, z.label, COALESCE(SUM(m.full_change),0) full_qty, COALESCE(SUM(m.empty_change),0) empty_qty,
    COALESCE(SUM(m.out_change),0) out_qty FROM cylinder_sizes z LEFT JOIN stock_movements m ON m.size_id=z.id
    WHERE z.is_active=1 GROUP BY z.id, z.label ORDER BY z.id`);
  const [{ sold }] = await q(`SELECT COALESCE(SUM(i.qty*i.unit_price),0) sold FROM sales s JOIN sale_items i ON i.sale_id=s.id WHERE s.sale_date=?`, [today()]);
  const [{ cs }] = await q(`SELECT COALESCE(SUM(i.qty*i.unit_price),0) cs FROM sales s JOIN sale_items i ON i.sale_id=s.id WHERE s.sale_date=? AND s.payment_type='cash'`, [today()]);
  const [{ pd }] = await q(`SELECT COALESCE(SUM(amount),0) pd FROM payments WHERE payment_date=?`, [today()]);
  const cust = await customers();
  res.json({ stock, sold: +sold, cash: +cs + +pd, unpaid: cust.reduce((t, c) => t + Math.max(0, +c.balance), 0) });
}));

/* sizes */
app.post('/api/sizes', owner, h(async (req, res) => {
  const { label, price } = req.body; if (!label) return bad(res, 'Size name required');
  await q('INSERT INTO cylinder_sizes (label,selling_price) VALUES (?,?)', [String(label).trim(), Number(price) || 0]); res.json({ ok: 1 });
}));
app.delete('/api/sizes/:id', owner, h(async (req, res) => { await q('UPDATE cylinder_sizes SET is_active=0 WHERE id=?', [req.params.id]); res.json({ ok: 1 }); }));

/* customers and payments */
app.get('/api/customers', h(async (req, res) => res.json(await customers())));
app.post('/api/customers', h(async (req, res) => {
  const { name, contact, address } = req.body; if (!name) return bad(res, 'Name required');
  await q('INSERT INTO customers (name,contact,address) VALUES (?,?,?)', [name, contact || null, address || null]); res.json({ ok: 1 });
}));
app.delete('/api/customers/:id', owner, h(async (req, res) => { await q('UPDATE customers SET is_active=0 WHERE id=?', [req.params.id]); res.json({ ok: 1 }); }));
app.post('/api/payments', h(async (req, res) => {
  const { customer_id, date, amount } = req.body; if (!customer_id || !(amount > 0)) return bad(res, 'Customer and amount required');
  await q('INSERT INTO payments (customer_id,payment_date,amount,created_by) VALUES (?,?,?,?)', [customer_id, date, amount, req.session.u.id]); res.json({ ok: 1 });
}));

/* plant deliveries */
app.get('/api/deliveries', h(async (req, res) => res.json(await q(`SELECT d.id,d.delivery_date,d.receipt_no,z.label size,i.full_received,i.empties_returned,u.name received_by
  FROM plant_deliveries d JOIN plant_delivery_items i ON i.delivery_id=d.id JOIN cylinder_sizes z ON z.id=i.size_id JOIN users u ON u.id=d.received_by
  ORDER BY d.delivery_date DESC,d.id DESC LIMIT 200`))));
app.post('/api/deliveries', h(async (req, res) => {
  const { date, receipt, remarks, items } = req.body; if (!date || !receipt || !items?.length) return bad(res, 'Date, receipt and items required');
  await tx(async c => {
    const [r] = await c.query('INSERT INTO plant_deliveries (delivery_date,receipt_no,remarks,received_by) VALUES (?,?,?,?)', [date, receipt, remarks || null, req.session.u.id]);
    for (const it of items) {
      const f = Math.max(0, int(it.full)), e = Math.max(0, int(it.empties));
      await c.query('INSERT INTO plant_delivery_items (delivery_id,size_id,full_received,empties_returned) VALUES (?,?,?,?)', [r.insertId, it.size_id, f, e]);
      await c.query("INSERT INTO stock_movements (move_date,size_id,ref_type,ref_id,full_change,empty_change) VALUES (?,?,'delivery',?,?,?)", [date, it.size_id, r.insertId, f, -e]);
    }
  });
  res.json({ ok: 1 });
}));
app.delete('/api/deliveries/:id', owner, h(async (req, res) => {
  await tx(async c => { await c.query("DELETE FROM stock_movements WHERE ref_type='delivery' AND ref_id=?", [req.params.id]); await c.query('DELETE FROM plant_deliveries WHERE id=?', [req.params.id]); });
  res.json({ ok: 1 });
}));

/* sales */
app.get('/api/sales', h(async (req, res) => res.json(await q(`SELECT s.id,s.sale_date,COALESCE(c.name,'Walk-in') customer,z.label size,i.qty,i.unit_price,i.empties_received,s.payment_type
  FROM sales s JOIN sale_items i ON i.sale_id=s.id JOIN cylinder_sizes z ON z.id=i.size_id LEFT JOIN customers c ON c.id=s.customer_id
  ORDER BY s.sale_date DESC,s.id DESC LIMIT 200`))));
app.post('/api/sales', h(async (req, res) => {
  const { date, customer_id, pay, items } = req.body;
  if (!date || !items?.length || !['cash', 'credit'].includes(pay)) return bad(res, 'Date, payment type and items required');
  if (pay === 'credit' && !customer_id) return bad(res, 'Pick a customer for a credit sale');
  for (const it of items) {
    const [{ f }] = await q('SELECT COALESCE(SUM(full_change),0) f FROM stock_movements WHERE size_id=?', [it.size_id]);
    if (int(it.qty) < 1) return bad(res, 'Quantity must be at least 1');
    if (+f < int(it.qty)) return bad(res, `Only ${f} full cylinders in stock`);
  }
  await tx(async c => {
    const [r] = await c.query('INSERT INTO sales (sale_date,customer_id,payment_type,created_by) VALUES (?,?,?,?)', [date, customer_id || null, pay, req.session.u.id]);
    for (const it of items) {
      const n = int(it.qty), b = Math.max(0, int(it.back));
      await c.query('INSERT INTO sale_items (sale_id,size_id,qty,unit_price,empties_received) VALUES (?,?,?,?,?)', [r.insertId, it.size_id, n, Number(it.price) || 0, b]);
      await c.query("INSERT INTO stock_movements (move_date,size_id,ref_type,ref_id,full_change,empty_change,out_change) VALUES (?,?,'sale',?,?,?,?)", [date, it.size_id, r.insertId, -n, b, n - b]);
    }
  });
  res.json({ ok: 1 });
}));
app.delete('/api/sales/:id', owner, h(async (req, res) => {
  await tx(async c => { await c.query("DELETE FROM stock_movements WHERE ref_type='sale' AND ref_id=?", [req.params.id]); await c.query('DELETE FROM sales WHERE id=?', [req.params.id]); });
  res.json({ ok: 1 });
}));

/* stock adjustments (opening count, damaged, lost) */
app.get('/api/adjustments', owner, h(async (req, res) => res.json(await q(`SELECT a.id,a.adj_date,z.label size,a.reason,a.full_change,a.empty_change
  FROM stock_adjustments a JOIN cylinder_sizes z ON z.id=a.size_id ORDER BY a.adj_date DESC,a.id DESC LIMIT 200`))));
app.post('/api/adjustments', owner, h(async (req, res) => {
  const { date, size_id, reason, full, empty } = req.body; if (!date || !size_id || !reason) return bad(res, 'Date, size and reason required');
  await tx(async c => {
    const [r] = await c.query('INSERT INTO stock_adjustments (adj_date,size_id,reason,full_change,empty_change,created_by) VALUES (?,?,?,?,?,?)', [date, size_id, reason, int(full), int(empty), req.session.u.id]);
    await c.query("INSERT INTO stock_movements (move_date,size_id,ref_type,ref_id,full_change,empty_change) VALUES (?,?,'adjustment',?,?,?)", [date, size_id, r.insertId, int(full), int(empty)]);
  });
  res.json({ ok: 1 });
}));
app.delete('/api/adjustments/:id', owner, h(async (req, res) => {
  await tx(async c => { await c.query("DELETE FROM stock_movements WHERE ref_type='adjustment' AND ref_id=?", [req.params.id]); await c.query('DELETE FROM stock_adjustments WHERE id=?', [req.params.id]); });
  res.json({ ok: 1 });
}));

/* orders (staff side) */
app.get('/api/orders', h(async (req, res) => res.json(await q(`SELECT o.id,o.order_date,c.name customer,z.label size,o.qty,o.payment_type,o.notes,o.status
  FROM orders o JOIN customers c ON c.id=o.customer_id JOIN cylinder_sizes z ON z.id=o.size_id
  ORDER BY FIELD(o.status,'pending','approved','delivered','cancelled'), o.id DESC LIMIT 200`))));
app.post('/api/orders/:id/approve', h(async (req, res) => {
  const r = await q("UPDATE orders SET status='approved' WHERE id=? AND status='pending'", [req.params.id]);
  if (!r.affectedRows) return bad(res, 'Order is not pending'); res.json({ ok: 1 });
}));
app.post('/api/orders/:id/cancel', h(async (req, res) => {
  const r = await q("UPDATE orders SET status='cancelled' WHERE id=? AND status IN ('pending','approved')", [req.params.id]);
  if (!r.affectedRows) return bad(res, 'Order cannot be cancelled'); res.json({ ok: 1 });
}));
// Delivering an order records it as a normal sale, so stock, balance and empties update
app.post('/api/orders/:id/deliver', h(async (req, res) => {
  const [o] = await q('SELECT o.*,z.selling_price FROM orders o JOIN cylinder_sizes z ON z.id=o.size_id WHERE o.id=?', [req.params.id]);
  if (!o || !['pending', 'approved'].includes(o.status)) return bad(res, 'Order cannot be delivered');
  const [{ f }] = await q('SELECT COALESCE(SUM(full_change),0) f FROM stock_movements WHERE size_id=?', [o.size_id]);
  if (+f < o.qty) return bad(res, `Only ${f} full cylinders in stock`);
  const back = Math.max(0, int(req.body.back)), d = today();
  await tx(async c => {
    const [r] = await c.query('INSERT INTO sales (sale_date,customer_id,payment_type,created_by) VALUES (?,?,?,?)', [d, o.customer_id, o.payment_type, req.session.u.id]);
    await c.query('INSERT INTO sale_items (sale_id,size_id,qty,unit_price,empties_received) VALUES (?,?,?,?,?)', [r.insertId, o.size_id, o.qty, o.selling_price, back]);
    await c.query("INSERT INTO stock_movements (move_date,size_id,ref_type,ref_id,full_change,empty_change,out_change) VALUES (?,?,'sale',?,?,?,?)", [d, o.size_id, r.insertId, -o.qty, back, o.qty - back]);
    await c.query("UPDATE orders SET status='delivered', sale_id=? WHERE id=?", [r.insertId, o.id]);
  });
  res.json({ ok: 1 });
}));

/* users (owner only) */
app.get('/api/users', owner, h(async (req, res) => res.json(await q('SELECT id,name,username,role FROM users WHERE is_active=1 ORDER BY id'))));
app.post('/api/users', owner, h(async (req, res) => {
  const { name, username, password, role, contact, address } = req.body;
  if (!name || !username || String(password || '').length < 6) return bad(res, 'Name, username and a password of 6+ characters required');
  if ((await q('SELECT id FROM users WHERE username=?', [username])).length) return bad(res, 'Username already taken');
  const r = ['owner', 'staff', 'customer'].includes(role) ? role : 'staff';
  let cid = null;
  if (r === 'customer') cid = (await q('INSERT INTO customers (name,contact,address) VALUES (?,?,?)', [name, contact || null, address || null])).insertId;
  await q('INSERT INTO users (name,username,password_hash,role,customer_id) VALUES (?,?,?,?,?)', [name, username, await bcrypt.hash(password, 10), r, cid]);
  res.json({ ok: 1 });
}));
app.delete('/api/users/:id', owner, h(async (req, res) => {
  if (+req.params.id === req.session.u.id) return bad(res, 'You cannot remove your own account');
  await q('UPDATE users SET is_active=0 WHERE id=?', [req.params.id]); res.json({ ok: 1 });
}));

/* ---------- start ---------- */
(async () => {
  const [{ n }] = await q('SELECT COUNT(*) n FROM users');
  if (!n) {
    await q("INSERT INTO users (name,username,password_hash,role) VALUES ('Owner','admin',?, 'owner')", [await bcrypt.hash('admin123', 10)]);
    console.log('Created first owner account: admin / admin123 (change it after logging in)');
  }
  const port = process.env.PORT || 3000;
  app.listen(port, () => console.log('LPG backend running on http://localhost:' + port));
})().catch(e => { console.error('Could not start. Check MySQL and your .env file.', e.message); process.exit(1); });
