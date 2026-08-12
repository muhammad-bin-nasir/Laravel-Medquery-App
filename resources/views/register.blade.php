<!DOCTYPE html>
<html>
<head>
    <title>Register</title>
</head>
<body style="font-family: sans-serif; max-width: 400px; margin: 50px auto;">
    <h2>Create an Account</h2>

    <form method="POST" action="/register">
        @csrf 
        
        <div style="margin-bottom: 15px;">
            <label>Name:</label><br>
            <input type="text" name="name" required>
        </div>

        <div style="margin-bottom: 15px;">
            <label>Email:</label><br>
            <input type="email" name="email" required>
        </div>

        <div style="margin-bottom: 15px;">
            <label>Password:</label><br>
            <input type="password" name="password" required>
        </div>

        <button type="submit">Register</button>
    </form>
</body>
</html>