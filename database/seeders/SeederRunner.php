<?php

require __DIR__ . '/DatabaseSeeder.php';

class SeederRunner
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
        
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }
    }

    public function run(): void
    {
        $seeder = new DatabaseSeeder($this->db);
        $seeder->run();
    }
}

