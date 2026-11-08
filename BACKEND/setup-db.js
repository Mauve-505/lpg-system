require('dotenv').config();
const fs = require('fs'), mysql = require('mysql2/promise');

(async () => {
  const db = await mysql.createConnection({
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASS || '',
    multipleStatements: true,
  });
  await db.query(fs.readFileSync('schema.sql', 'utf8'));
  console.log('Database ready: lpg_db');
  await db.end();
})().catch(e => console.error('Failed:', e.message));
