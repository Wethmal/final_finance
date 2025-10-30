<?php
// login.php - User login page
session_start();
require_once __DIR__ . '/../db/database.php';




$error = '';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password";
    } else {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            // Get user from database
            $stmt = $conn->prepare("SELECT * FROM users WHERE username = :username");
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password'])) {
                // Login successful
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                
                header('Location: dashboard.php');
                exit();
            } else {
                $error = "Invalid username or password";
            }
        } catch(PDOException $e) {
            $error = "Login failed: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - PocketLedge</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
        }
        
        body.scroll-active {
            align-items: flex-start;
            padding: 40px 20px;
        }
        
        /* Animated background shapes */
        body::before,
        body::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            animation: float 20s infinite ease-in-out;
            z-index: 0;
        }
        
        body::before {
            width: 400px;
            height: 400px;
            top: -200px;
            left: -200px;
        }
        
        body::after {
            width: 600px;
            height: 600px;
            bottom: -300px;
            right: -300px;
            animation-delay: -10s;
        }
        
        @keyframes float {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            33% { transform: translate(30px, -50px) rotate(120deg); }
            66% { transform: translate(-20px, 20px) rotate(240deg); }
        }
        
        .container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 50px 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 450px;
            position: relative;
            z-index: 10;
            animation: slideUp 0.6s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo i {
            font-size: 50px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 10px;
        }
        
        .logo h1 {
            font-size: 32px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 5px;
        }
        
        .logo p {
            color: #666;
            font-size: 14px;
            font-weight: 400;
        }
        
        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 35px;
            font-weight: 600;
            font-size: 24px;
        }
        
        .form-group {
            margin-bottom: 25px;
            position: relative;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
            font-size: 14px;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input-wrapper i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 18px;
        }
        
        input {
            width: 100%;
            padding: 14px 15px 14px 45px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
            background: #fafafa;
        }
        
        input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }
        
        .forgot-password {
            text-align: right;
            margin-top: -15px;
            margin-bottom: 25px;
        }
        
        .forgot-password a {
            color: #667eea;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: color 0.3s;
        }
        
        .forgot-password a:hover {
            color: #764ba2;
        }
        
        button {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
            position: relative;
            overflow: hidden;
        }
        
        button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
            transition: left 0.3s ease;
        }
        
        button span {
            position: relative;
            z-index: 1;
        }
        
        button:hover::before {
            left: 0;
        }
        
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }
        
        button:active {
            transform: translateY(0);
        }
        
        .error {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            animation: shake 0.5s ease;
            box-shadow: 0 5px 15px rgba(255, 107, 107, 0.3);
        }
        
        .error i {
            margin-right: 10px;
            font-size: 20px;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
        
        .divider {
            display: flex;
            align-items: center;
            margin: 30px 0;
            color: #999;
            font-size: 14px;
        }
        
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e0e0e0;
        }
        
        .divider::before {
            margin-right: 15px;
        }
        
        .divider::after {
            margin-left: 15px;
        }
        
        .social-login {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .social-btn {
            flex: 1;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        
        .social-btn:hover {
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .social-btn.google { color: #db4437; }
        .social-btn.facebook { color: #4267B2; }
        .social-btn.apple { color: #000; }
        
        .signup-link {
            text-align: center;
            margin-top: 25px;
            color: #666;
            font-size: 14px;
        }
        
        .signup-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }
        
        .signup-link a:hover {
            color: #764ba2;
            text-decoration: underline;
        }
        
        .features {
            display: flex;
            justify-content: space-around;
            margin-top: 30px;
            padding-top: 25px;
            border-top: 1px solid #e0e0e0;
        }
        
        .feature {
            text-align: center;
            flex: 1;
        }
        
        .feature i {
            font-size: 24px;
            color: #667eea;
            margin-bottom: 5px;
        }
        
        .feature p {
            font-size: 12px;
            color: #666;
        }
        
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .container {
                padding: 35px 25px;
                max-width: 100%;
            }
            
            .logo h1 {
                font-size: 26px;
            }
            
            .logo i {
                font-size: 40px;
            }
            
            h2 {
                font-size: 20px;
                margin-bottom: 25px;
            }
            
            .form-group {
                margin-bottom: 20px;
            }
            
            input {
                padding: 12px 12px 12px 40px;
                font-size: 14px;
            }
            
            .input-wrapper i {
                left: 12px;
                font-size: 16px;
            }
            
            button {
                padding: 13px;
                font-size: 15px;
            }
            
            .social-login {
                gap: 10px;
            }
            
            .social-btn {
                padding: 10px;
                font-size: 18px;
            }
        }
        
        @media (max-width: 480px) {
            body {
                padding: 5px;
            }
            
            .container {
                padding: 30px 20px;
                border-radius: 15px;
            }
            
            .logo h1 {
                font-size: 24px;
            }
            
            .logo i {
                font-size: 36px;
            }
            
            .logo p {
                font-size: 12px;
            }
            
            h2 {
                font-size: 18px;
                margin-bottom: 20px;
            }
            
            .form-group {
                margin-bottom: 18px;
            }
            
            label {
                font-size: 13px;
                margin-bottom: 6px;
            }
            
            input {
                padding: 11px 11px 11px 38px;
                font-size: 13px;
            }
            
            .input-wrapper i {
                left: 11px;
                font-size: 15px;
            }
            
            button {
                padding: 12px;
                font-size: 14px;
            }
            
            .forgot-password {
                margin-top: -10px;
                margin-bottom: 20px;
            }
            
            .forgot-password a {
                font-size: 12px;
            }
            
            .divider {
                margin: 20px 0;
                font-size: 13px;
            }
            
            .social-login {
                gap: 8px;
            }
            
            .social-btn {
                padding: 10px 8px;
                font-size: 16px;
            }
            
            .signup-link {
                font-size: 13px;
                margin-top: 20px;
            }
            
            .features {
                margin-top: 20px;
                padding-top: 20px;
            }
            
            .feature i {
                font-size: 20px;
            }
            
            .feature p {
                font-size: 11px;
            }
            
            .error, .success {
                padding: 12px;
                font-size: 13px;
            }
            
            .error i, .success i {
                font-size: 16px;
            }
        }
        
        @media (max-width: 360px) {
            .container {
                padding: 25px 15px;
            }
            
            .logo h1 {
                font-size: 22px;
            }
            
            input {
                padding: 10px 10px 10px 36px;
                font-size: 12px;
            }
            
            .social-login {
                flex-direction: column;
            }
            
            .social-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <i class="fas fa-wallet"></i>
            <h1>PocketLedge</h1>
            <p>Your Smart Finance Companion</p>
        </div>
        
        <h2>Welcome Back!</h2>
        
        <?php if ($error): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Username</label>
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" id="username" name="username" placeholder="Enter your username" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>
            </div>
            
            <div class="forgot-password">
                <a href="#">Forgot Password?</a>
            </div>
            
            <button type="submit">
                <span>Login to Your Account</span>
            </button>
        </form>
        
        <div class="divider">OR</div>
        
        <div class="social-login">
            <div class="social-btn google" title="Continue with Google">
                <i class="fab fa-google"></i>
            </div>
            <div class="social-btn facebook" title="Continue with Facebook">
                <i class="fab fa-facebook-f"></i>
            </div>
            <div class="social-btn apple" title="Continue with Apple">
                <i class="fab fa-apple"></i>
            </div>
        </div>
        
        <div class="signup-link">
            Don't have an account? <a href="signup.php">Create one now</a>
        </div>
        
        <div class="features">
            <div class="feature">
                <i class="fas fa-shield-alt"></i>
                <p>Secure</p>
            </div>
            <div class="feature">
                <i class="fas fa-bolt"></i>
                <p>Fast</p>
            </div>
            <div class="feature">
                <i class="fas fa-chart-line"></i>
                <p>Smart</p>
            </div>
        </div>
    </div>
</body>
</html>