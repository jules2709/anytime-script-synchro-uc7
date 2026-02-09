<?php
declare(strict_types=1);

namespace HistorySync;

use PDO;
use PDOException;

class Utils
{
    /**
     * Établit la connexion à MariaDB.
     *
     * @param array $dbConfig Configuration de la base de données
     * @return PDO Instance PDO connectée
     * @throws PDOException Si la connexion échoue
     */
    public static function getDbConnection(array $dbConfig): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $dbConfig['host'],
            $dbConfig['port'],
            $dbConfig['database']
        );

        return new PDO(
            $dsn,
            $dbConfig['user'],
            $dbConfig['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
    }
}
