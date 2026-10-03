const fs = require("fs");
const path = require("path");
const mysql = require("mysql2/promise");
require("dotenv").config({ path: path.join(__dirname, "..", ".env") });

async function main() {
  const connection = await mysql.createConnection({
    host: process.env.DB_HOST || "127.0.0.1",
    user: process.env.DB_USER || "syner_remoteo",
    password: process.env.DB_PASS || "",
    database: process.env.DB_NAME || "syner_remoteo",
    multipleStatements: true
  });
  try {
    const sql = fs.readFileSync(path.join(__dirname, "004_agent_activity.sql"), "utf8");
    await connection.query(sql);
    console.log("004_agent_activity: OK");
  } finally {
    await connection.end();
  }
}

main().catch((error) => {
  console.error("004_agent_activity: ERROR", error.message);
  process.exit(1);
});
