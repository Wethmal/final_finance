<?php
// db/database.php - Database connection and setup

class Database {
    private $db;
    private $dbFile;

    public function __construct() {
        try {
            // Path to SQLite database
            $this->dbFile = __DIR__ . '/database.db';

            // Check if DB file exists
            $dbExists = file_exists($this->dbFile);

            // If exists but empty → delete
            if ($dbExists && filesize($this->dbFile) == 0) {
                unlink($this->dbFile);
                $dbExists = false;
            }

            // Connect or create new DB
            $this->db = new PDO('sqlite:' . $this->dbFile);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Enable foreign keys for SQLite
            $this->db->exec('PRAGMA foreign_keys = ON');

            // Create tables if not exist
            $this->createTables();

        } catch(PDOException $e) {
            // Recreate on failure
            if (file_exists($this->dbFile)) unlink($this->dbFile);
            try {
                $this->db = new PDO('sqlite:' . $this->dbFile);
                $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->db->exec('PRAGMA foreign_keys = ON');
                $this->createTables();
            } catch(PDOException $e2) {
                die("Database connection failed: " . $e2->getMessage());
            }
        }
    }

    private function createTables() {
        // Create users table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                email TEXT UNIQUE NOT NULL,
                password TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Create budgets table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS budgets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                category TEXT NOT NULL,
                budget_amount REAL NOT NULL,
                created_date TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");

        // Create expenses table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS expenses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                budget_id INTEGER NOT NULL,
                amount REAL NOT NULL,
                description TEXT,
                date TEXT NOT NULL,
                FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE
            )
        ");
    }

    public function getConnection() {
        return $this->db;
    }
}
?>