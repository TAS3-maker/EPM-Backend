<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Project Assigned</title>
</head>
<body style="font-family: Arial, sans-serif; background-color:#f9f9f9; padding: 0; margin: 0;">
    <table width="100%" border="0" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center" style="padding: 20px 0;">
                <img src="{{ asset('images/logo.png') }}" alt="{{ config('app.name') }}" style="height: 50px;">
            </td>
        </tr>
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background: white; border-radius: 8px; padding: 20px;">
                    <tr>
                        <td>
                            <h2 style="color:#333;">Hello {{ $manager->name }},</h2>
                            <p style="font-size: 15px; color: #555;">
                                You have been assigned to a new project in our system.
                            </p>

                            <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
                                <tr>
                                    <td style="padding: 8px; border: 1px solid #eee;"><strong>Project Name</strong></td>
                                    <td style="padding: 8px; border: 1px solid #eee;">{{ $project->project_sname }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px; border: 1px solid #eee;"><strong>Project ID</strong></td>
                                    <td style="padding: 8px; border: 1px solid #eee;">{{ $project->id }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px; border: 1px solid #eee;"><strong>Assigned By</strong></td>
                                    <td style="padding: 8px; border: 1px solid #eee;">{{ $assigner->name }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px; border: 1px solid #eee;"><strong>Assigned Date</strong></td>
                                    <td style="padding: 8px; border: 1px solid #eee;">{{ now()->format('d M Y, h:i A') }}</td>
                                </tr>
                            </table>

                            <p style="text-align: center;">
                                <a href="https://epm.techarchsoftwares.com/projectmanager/tasks/{{ $project->id }}
                                   style="display: inline-block; padding: 12px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px;">
                                    View Project
                                </a>
                            </p>

                            <p style="font-size: 14px; color: #777; margin-top: 20px;">
                                Thanks
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
