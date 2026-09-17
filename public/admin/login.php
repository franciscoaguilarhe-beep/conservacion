<?php
/**
 * Acceso Administrativo - Login IMSS Conservación
 */
require_once __DIR__ . '/../../config/config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(filter_input(INPUT_POST, 'username', FILTER_SANITIZE_SPECIAL_CHARS));
    $password = trim(filter_input(INPUT_POST, 'password', FILTER_DEFAULT));

    if (empty($username) || empty($password)) {
        $error = 'Por favor ingrese su usuario y contraseña.';
    } else {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM usuarios_admin WHERE username = ? AND estatus = 'Activo' LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        $authSuccess = false;
        if ($user) {
            // Verificación con hash o contraseñas iniciales de desarrollo
            if (password_verify($password, $user['password_hash']) || 
                ($password === 'imss2026') || 
                ($password === 'admin123' && $user['username'] === 'superadmin')) {
                $authSuccess = true;
            }
        }

        if ($authSuccess) {
            $stmtUnits = $db->prepare("SELECT unidad_id FROM usuarios_unidades WHERE usuario_id = ?");
            $stmtUnits->execute([$user['id']]);
            $userUnidades = $stmtUnits->fetchAll(PDO::FETCH_COLUMN);

            if (empty($userUnidades) && !empty($user['unidad_id'])) {
                $userUnidades = [(int)$user['unidad_id']];
            }

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['nombre_completo'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_rol'] = $user['rol'];
            $_SESSION['user_unidades'] = $userUnidades;
            $_SESSION['user_unidad_id'] = $userUnidades[0] ?? $user['unidad_id'] ?? null;

            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Credenciales no válidas o usuario inactivo.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Administrativo - Conservación IMSS</title>
        <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="icon" type="image/png" sizes="32x32" href="../favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../favicon-16.png">
    <link rel="icon" type="image/png" href="../conservacion.png">
    <link rel="apple-touch-icon" sizes="180x180" href="../apple-touch-icon.png">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#0C6839">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Conservación IMSS">
    <style>
        body {
            background-color: #e5ede8;
            background-image: radial-gradient(#d3dfd7 1px, transparent 1px);
            background-size: 20px 20px;
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .login-card {
            background-color: #1a1a1a;
            border-radius: 12px;
            padding: 3rem;
            width: 100%;
            max-width: 450px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            color: white;
            text-align: center;
        }
        .logo-container {
            margin-bottom: 1.5rem;
        }
        .login-card h2 {
            font-size: 1.75rem;
            margin: 0 0 0.5rem 0;
            font-weight: 700;
        }
        .login-card p {
            color: #999;
            margin: 0 0 2rem 0;
            font-size: 1rem;
        }
        .divider {
            width: 80px;
            height: 2px;
            background-color: #3b8c4c;
            margin: 0 auto 2rem auto;
        }
        .form-group-login {
            text-align: left;
            margin-bottom: 1.5rem;
        }
        .form-group-login label {
            display: block;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        .input-icon-wrapper {
            position: relative;
        }
        .input-icon-wrapper svg {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
            pointer-events: none;
        }
        .input-icon-wrapper .eye-icon {
            left: auto;
            right: 1rem;
            cursor: pointer;
            pointer-events: all;
        }
        input.form-input-login {
            width: 100%;
            background-color: #242424;
            border: 1px solid #444;
            color: white;
            padding: 1rem 1rem 1rem 3.5rem;
            border-radius: 8px;
            font-size: 1rem;
            box-sizing: border-box;
        }
        input.form-input-login:focus {
            outline: none;
            border-color: #3b8c4c;
        }
        .btn-login {
            background-color: #2b7a4b;
            color: white;
            border: none;
            padding: 1rem;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: bold;
            width: 100%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            margin-top: 1rem;
        }
        .btn-login:hover {
            background-color: #23633d;
        }
        .login-footer {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #333;
            color: #888;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .login-footer svg {
            color: #3b8c4c;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo-container">
            <img src="../../Images/IMSS.png" alt="Logo IMSS" style="width: 120px; height: auto;">
        </div>

        <h2>Control de Conservación</h2>
        <p>Portal Administrativo</p>
        
        <div class="divider"></div>

        <?php if ($error): ?>
            <div style="background-color: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); color: #FCA5A5; padding: 0.75rem; border-radius: 0.5rem; font-size: 0.85rem; margin-bottom: 1.25rem;">
                <?= h($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="form-group-login">
                <label for="username">Usuario</label>
                <div class="input-icon-wrapper">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2m8-10a4 4 0 100-8 4 4 0 000 8z"></path></svg>
                    <input type="text" id="username" name="username" class="form-input-login" placeholder="Ingrese su usuario" required autofocus>
                </div>
            </div>

            <div class="form-group-login">
                <label for="password">Contraseña</label>
                <div class="input-icon-wrapper">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    <input type="password" id="password" name="password" class="form-input-login" placeholder="Ingrese su contraseña" required>
                    <svg class="eye-icon" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                </div>
            </div>

            <button type="submit" class="btn-login">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                Ingresar al Panel
            </button>
        </form>

        <div class="login-footer">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            Acceso exclusivo para Jefe, Subjefe y AUO.
        </div>
        
        <!-- Link de test / back to index (oculto en el diseño final pero útil para dev) -->
        <div style="margin-top: 1rem; font-size: 0.75rem;">
            <a href="../index.php" style="color: #555; text-decoration: none;">Volver al Portal</a>
        </div>
    </div>
</body>
</html>
