<?php
// signup.php - User registration page
session_start();
require_once __DIR__ . '/../db/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validation
    if (empty($username) || empty($email) || empty($password)) {
        $error = "All fields are required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } else {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert user
            $stmt = $conn->prepare("INSERT INTO users (username, email, password) VALUES (:username, :email, :password)");
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':password', $hashed_password);
            
            if ($stmt->execute()) {
                $success = "Registration successful! You can now login.";
            }
        } catch(PDOException $e) {
            if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                $error = "Username or email already exists";
            } else {
                $error = "Registration failed: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - PocketLedge</title>
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
            max-width: 480px;
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
            margin-bottom: 20px;
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
        
        .password-strength {
            margin-top: 5px;
            font-size: 12px;
            display: none;
        }
        
        .password-strength.active {
            display: block;
        }
        
        .strength-bars {
            display: flex;
            gap: 5px;
            margin-top: 5px;
        }
        
        .strength-bar {
            height: 4px;
            flex: 1;
            background: #e0e0e0;
            border-radius: 2px;
            transition: background 0.3s;
        }
        
        .strength-bar.active.weak { background: #ff6b6b; }
        .strength-bar.active.medium { background: #feca57; }
        .strength-bar.active.strong { background: #48dbfb; }
        .strength-bar.active.very-strong { background: #1dd1a1; }
        
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
            margin-top: 10px;
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
        
        .success {
            background: linear-gradient(135deg, #1dd1a1 0%, #10ac84 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            animation: slideDown 0.5s ease;
            box-shadow: 0 5px 15px rgba(29, 209, 161, 0.3);
        }
        
        .success i {
            margin-right: 10px;
            font-size: 20px;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
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
        
        .login-link {
            text-align: center;
            margin-top: 25px;
            color: #666;
            font-size: 14px;
        }
        
        .login-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }
        
        .login-link a:hover {
            color: #764ba2;
            text-decoration: underline;
        }
        
        .terms {
            text-align: center;
            margin-top: 20px;
            font-size: 12px;
            color: #999;
        }
        
        .terms a {
            color: #667eea;
            text-decoration: none;
        }
        
        .terms a:hover {
            text-decoration: underline;
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
                margin-bottom: 18px;
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
                margin-bottom: 16px;
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
                margin-top: 5px;
            }
            
            .divider {
                margin: 20px 0;
                font-size: 13px;
            }
            
            .social-login {
                gap: 8px;
                margin-bottom: 20px;
            }
            
            .social-btn {
                padding: 10px 8px;
                font-size: 16px;
            }
            
            .login-link {
                font-size: 13px;
                margin-top: 20px;
            }
            
            .terms {
                font-size: 11px;
                margin-top: 15px;
            }
            
            .error, .success {
                padding: 12px;
                font-size: 13px;
                margin-bottom: 20px;
            }
            
            .error i, .success i {
                font-size: 16px;
            }
            
            .password-strength {
                font-size: 11px;
            }
            
            .strength-bar {
                height: 3px;
            }
        }
        
        @media (max-width: 360px) {
            .container {
                padding: 25px 15px;
            }
            
            .logo h1 {
                font-size: 22px;
            }
            
            h2 {
                font-size: 17px;
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
        
        <h2>Create Your Account</h2>
        
        <?php if ($error): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" id="signupForm">
            <div class="form-group">
                <label for="username">Username</label>
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" id="username" name="username" placeholder="Choose a username" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="email">Email Address</label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope"></i>
                    <input type="email" id="email" name="email" placeholder="Enter your email" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" placeholder="Create a password" required>
                </div>
                <div class="password-strength" id="passwordStrength">
                    <div class="strength-bars">
                        <div class="strength-bar"></div>
                        <div class="strength-bar"></div>
                        <div class="strength-bar"></div>
                        <div class="strength-bar"></div>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm your password" required>
                </div>
            </div>
            
            <button type="submit">
                <span>Create Account</span>
            </button>
        </form>
        
        <div class="divider">OR</div>
        
        <div class="social-login">
            <div class="social-btn google" title="Sign up with Google">
                <i class="fab fa-google"></i>
            </div>
            <div class="social-btn facebook" title="Sign up with Facebook">
                <i class="fab fa-facebook-f"></i>
            </div>
            <div class="social-btn apple" title="Sign up with Apple">
                <i class="fab fa-apple"></i>
            </div>
        </div>
        
        <div class="login-link">
            Already have an account? <a href="login.php">Login here</a>
        </div>
        
        <div class="terms">
            By signing up, you agree to our <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a>
        </div>
    </div>
    
    <script>
        // Password strength indicator
        const passwordInput = document.getElementById('password');
        const strengthIndicator = document.getElementById('passwordStrength');
        const strengthBars = document.querySelectorAll('.strength-bar');
        
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            const strength = calculatePasswordStrength(password);
            
            if (password.length > 0) {
                strengthIndicator.classList.add('active');
                updateStrengthBars(strength);
            } else {
                strengthIndicator.classList.remove('active');
            }
        });
        
        function calculatePasswordStrength(password) {
            let strength = 0;
            
            if (password.length >= 6) strength++;
            if (password.length >= 10) strength++;
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
            if (/\d/.test(password)) strength++;
            if (/[^a-zA-Z\d]/.test(password)) strength++;
            
            return Math.min(strength, 4);
        }
        
        function updateStrengthBars(strength) {
            strengthBars.forEach((bar, index) => {
                bar.classList.remove('active', 'weak', 'medium', 'strong', 'very-strong');
                
                if (index < strength) {
                    bar.classList.add('active');
                    
                    if (strength === 1) bar.classList.add('weak');
                    else if (strength === 2) bar.classList.add('medium');
                    else if (strength === 3) bar.classList.add('strong');
                    else if (strength === 4) bar.classList.add('very-strong');
                }
            });
        }
        
        // Form validation
        document.getElementById('signupForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
            }
        });
    </script>
</body>
</html>