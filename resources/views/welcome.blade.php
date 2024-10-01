<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Webhook Status</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">

    <!-- Styles -->
    <style>
        body {
            font-family: 'Nunito', sans-serif;
            background-color: #f7fafc;
            color: #2d3748;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .container {
            text-align: center;
            background-color: #ffffff;
            padding: 50px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            animation: fadeIn 2s ease-in-out;
        }

        h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            color: #3182ce;
        }

        p {
            font-size: 1.25rem;
            margin-bottom: 20px;
        }

        .status {
            font-size: 1.5rem;
            color: #38a169;
            font-weight: bold;
            background-color: #e6fffa;
            padding: 10px 20px;
            border-radius: 5px;
            display: inline-block;
            animation: pulse 2s infinite;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        footer {
            position: absolute;
            bottom: 10px;
            text-align: center;
            width: 100%;
            color: #718096;
            font-size: 0.875rem;
        }

        footer a {
            color: #3182ce;
            text-decoration: none;
            margin: 0 5px;
        }

        footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>¡Webhook Activo!</h1>
        <p>Tu sitio de webhook está actualmente desplegado y funcionando.</p>
        <div class="status">Estado: Operativo :D</div>
    </div>

    <footer>
        <p>© 2024 - Desarrollado con <span style="color: red;">♥</span> por tu equipo.
        Visita nuestro <a target="_blank" href="https://niki.com.co">Maddi Go</a> para más detalles.</p>
    </footer>
</body>
</html>
