<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Project Assigned</title>
</head>
<body>
    <p>Hi {{ $employee->name }},</p>

    <p>You have been assigned to the project <strong>{{ $project->name }}</strong>.</p>

    <p>Please login to the portal for more details.</p>

    <p>Thanks,<br>
    {{ config('app.name') }}</p>
</body>
</html>
