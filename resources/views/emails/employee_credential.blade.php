<!DOCTYPE html>
<html>
<head>
    <title>Welcome</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .card {
            max-width: 600px;
            margin: 50px auto;
            border-radius: 10px;
            box-shadow: 0px 0px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .card-header {
            background-color: #E14A15;
            color: white;
            font-size: 1.5rem;
            text-align: center;
            padding: 20px;
        }
        .logo-container {
            text-align: center;
            background-color: #fff;
            padding: 20px 0;
            border-bottom: 1px solid #e9ecef;
        }
        .logo-container img {
            max-height: 60px;
        }
        .card-body {
            padding: 30px;
        }
        .credentials-list li {
            margin-bottom: 10px;
        }
        .text-muted {
            color: #6c757d !important;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-header">
            Welcome to the Team!
        </div>
        <div class="logo-container">
            <img src="{{ asset('images/taslogo.png') }}" alt="Company Logo">
        </div>
        <div class="card-body">
            <p class="mb-4">Hello,</p>
            <p>Your account has been created successfully. Please find your credentials below:</p>
            <ul class="list-group list-group-flush credentials-list">
                <li class="list-group-item"><strong>Email:</strong> {{ $email }}</li>
                <li class="list-group-item"><strong>Password:</strong> {{ $password }}</li>
                <li class="list-group-item"><strong>Department:</strong> {{ $team ?? 'N/A' }}</li>
                <li class="list-group-item"><strong>Role:</strong> {{ $role }}</li>
            </ul>
            <p class="mt-4">Please log in and change your password after your first login.</p>
            <p class="text-muted">If you have any questions, feel free to reach out to the admin.</p>
        </div>
    </div>
</body>
</html>
