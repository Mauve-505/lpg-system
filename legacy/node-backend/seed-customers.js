require('dotenv').config();
const mysql = require('mysql2/promise'), bcrypt = require('bcryptjs');

const people = [
  ['Aling Rosa Store', 'rosa', 'Tulay, Jolo'],
  ['Kuya Jun Eatery', 'jun', 'Walled City, Jolo'],
  ['Mang Ben Carinderia', 'ben', 'Bus-Bus, Jolo'],
];

(async () => {
  const db = await mysql.createConnection({
    host: process.env.DB_HOST || 'localhost', user: process.env.DB_USER || 'root',
    password: process.env.DB_PASS || '', database: process.env.DB_NAME || 'lpg_db',
  });
  const hash = await bcrypt.hash('customer123', 10);
  for (const [name, username, address] of people) {
    const [x] = await db.query('SELECT id FROM users WHERE username=?', [username]);
    if (x.length) { console.log('Skipped (exists):', username); continue; }
    const [c] = await db.query('INSERT INTO customers (name,address) VALUES (?,?)', [name, address]);
    await db.query("INSERT INTO users (name,username,password_hash,role,customer_id) VALUES (?,?,?,'customer',?)", [name, username, hash, c.insertId]);
    console.log('Created', name, '→ login:', username, '/ customer123');
  }
  await db.end();
})();
