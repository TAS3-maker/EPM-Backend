<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Project Assigned</title>
</head>
<body style="font-family: Arial, sans-serif;">
    <h2>Hello {{ $tl->name }},</h2>
    <p>You have been assigned as a <strong>Team Leader</strong> for a new project.</p>

    <p><strong>Project Name:</strong> {{ $project->name }}</p>
    <p><strong>Project ID:</strong> {{ $project->id }}</p>
    <p><strong>Assigned By:</strong> {{ $assigner->name }}</p>
    <p><strong>Assigned Date:</strong> {{ now()->format('d M Y, h:i A') }}</p>

    <p>
        <a href="{{ config('app.frontend_url') }}/projects/{{ $project->id }}" 
           style="background: #28a745; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px;">
            View Project
        </a>
    </p>

    <p>Thanks,<br>{{ config('app.name') }}</p>
</body>
</html>
