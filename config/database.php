<?php
/**
 * Conexión a Base de Datos MySQL usando PDO
 * Plataforma de Gestión de Conservación IMSS
 */

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $dbname = getenv('DB_NAME') ?: 'imss_conservacion';
            $user = getenv('DB_USER') ?: 'imss_user';
            $pass = getenv('DB_PASS') ?: 'imss_secret';
            $port = getenv('DB_PORT') ?: '3306';

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                // Si falla en localhost (ej. fuera del contenedor sin env), intentar root sin pass
                try {
                    $fallbackDsn = "mysql:host=127.0.0.1;port=3306;dbname=imss_conservacion;charset=utf8mb4";
                    self::$instance = new PDO($fallbackDsn, 'root', '', $options);
                } catch (PDOException $e2) {
                    die(json_encode([
                        'success' => false,
                        'error' => 'Error de conexión a la base de datos: ' . $e->getMessage()
                    ]));
                }
            }

            self::ensureSchema(self::$instance);
        }
        return self::$instance;
    }

    private static function ensureSchema(PDO $pdo): void {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS usuarios_unidades (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    usuario_id INT NOT NULL,
                    unidad_id INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_usuario_unidad (usuario_id, unidad_id),
                    FOREIGN KEY (usuario_id) REFERENCES usuarios_admin(id) ON DELETE CASCADE,
                    FOREIGN KEY (unidad_id) REFERENCES unidades(id) ON DELETE CASCADE
                ) ENGINE=InnoDB;
            ");

            // Migración automática de registros previos
            $count = (int)$pdo->query("SELECT COUNT(*) FROM usuarios_unidades")->fetchColumn();
            if ($count === 0) {
                $pdo->exec("
                    INSERT IGNORE INTO usuarios_unidades (usuario_id, unidad_id)
                    SELECT id, unidad_id FROM usuarios_admin WHERE unidad_id IS NOT NULL
                ");
            }
        } catch (Exception $e) {
            // Ignorar silenciosamente si ya existe
        }
    }
}
