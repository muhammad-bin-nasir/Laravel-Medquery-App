<!DOCTYPE html>
<html>
<head>
    <title>Login</title>
</head>
<body style="font-family: sans-serif; max-width: 400px; margin: 50px auto;">
    <h2>Welcome Back</h2>

    @if ($errors->any())
        <div style="color: red; background-color: #fee; padding: 10px; border: 1px solid red; margin-bottom: 15px;">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="/login">
        @csrf 
        
        <div style="margin-bottom: 15px;">
            <label>Email:</label><br>
            <input type="email" name="email" required>
        </div>

        <div style="margin-bottom: 15px;">
            <label>Password:</label><br>
            <input type="password" name="password" required>
        </div>

        <button type="submit">Login</button>
    </form>
</body>
</html>