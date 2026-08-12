<!DOCTYPE html>
<html>
<head>
    <title>My App</title>
</head>
<body style="font-family: sans-serif; text-align: center; margin-top: 50px;">
    
    @if(Auth::check())
        <h1>Welcome back, {{ Auth::user()->name }}!</h1>
        <p>Your email is: {{ Auth::user()->email }}</p>
        
        <form method="POST" action="/logout">
            @csrf
            <button type="submit">Logout</button>
        </form>
    @else
        <h1>Welcome to Laravel!</h1>
        <p>You are not logged in.</p>
        <a href="/login">Login</a> | <a href="/register">Register</a>
    @endif

</body>
</html>