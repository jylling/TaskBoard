import mysql from 'mysql2/promise';

export const db = mysql.createPool({
    host: 'localhost',
    user: 'board',
    password: 'xxx',
    database: 'board',
    waitForConnections: true,
    connectionLimit: 10
});
