<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My App</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <style>
        /* A little custom spacing */
        nav { border-bottom: 1px solid #e2e8f0; margin-bottom: 2rem; padding: 1rem 0; }
    </style>
</head>
<body>
    <nav class="container">
        <ul>
            <li><strong><a href="/" style="text-decoration: none;">Medical Portal</a></strong></li>
        </ul>
        <ul>
            @auth
                <li><a href="/dashboard">Dashboard</a></li>
                <li>
                    <form method="POST" action="/logout" style="margin: 0;">
                        @csrf
                        <button type="submit" class="outline" style="padding: 0.25rem 1rem;">Logout</button>
                    </form>
                </li>
            @else
                <li><a href="/login">Login</a></li>
                <li><a href="/register">Register</a></li>
            @endauth
        </ul>
    </nav>

    <main class="container">
        @if (session('success'))
            <article style="background-color: #d1e7dd; color: #0f5132; border-color: #badbcc; margin-bottom: 2rem;">
                {{ session('success') }}
            </article>
        @endif

        @yield('content')
    </main>

</body>
</html>