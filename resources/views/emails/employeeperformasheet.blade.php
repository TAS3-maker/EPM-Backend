<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Performa Sheets Submitted</title>
    <style>
        @media only screen and (max-width: 600px) {
            .container {
                width: 100% !important;
                padding: 15px !important;
            }

            .table th, .table td {
                font-size: 12px !important;
                padding: 6px !important;
            }

            h2 {
                font-size: 18px !important;
            }

            p {
                font-size: 14px !important;
            }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f9f9f9; color: #333;">

    <div class="container" style="max-width: 700px; margin: auto; background: #ffffff; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); padding: 30px;">

        <!-- Header -->
        <div style="background-color: #e04a15; color: white; padding: 20px 30px; border-top-left-radius: 8px; border-top-right-radius: 8px;">
            <h2 style="margin: 0; font-size: 22px;">New Performa Sheets Submitted</h2>
        </div>

        <!-- Body -->
        <div style="padding: 20px 30px;">
            <p style="font-size: 16px; margin-top: 0;">Hello Admin,</p>
            <p style="font-size: 16px;"><strong>{{ $user->name }}</strong> has submitted the following Performa Sheets:</p>

            <!-- Table -->
            <div style="overflow-x: auto;">
                <table class="table" width="100%" style="border-collapse: collapse; margin-top: 20px; min-width: 600px;">
                    <thead>
                        <tr style="background-color: #f2f2f2; color: #333;">
                            <th style="padding: 10px; border: 1px solid #ddd;">Project Name</th>
                            <th style="padding: 10px; border: 1px solid #ddd;">Date</th>
                            <th style="padding: 10px; border: 1px solid #ddd;">Time</th>
                            <th style="padding: 10px; border: 1px solid #ddd;">Work Type</th>
                            <th style="padding: 10px; border: 1px solid #ddd;">Activity Type</th>
                            <th style="padding: 10px; border: 1px solid #ddd;">Narration</th>
                            <th style="padding: 10px; border: 1px solid #ddd;">Project Type</th>
                            <th style="padding: 10px; border: 1px solid #ddd;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sheets as $sheet)
                            <tr>
                                <td style="padding: 10px; border: 1px solid #ddd;">{{ $sheet['project_name'] }}</td>
                                <td style="padding: 10px; border: 1px solid #ddd;">{{ $sheet['date'] }}</td>
                                <td style="padding: 10px; border: 1px solid #ddd;">{{ $sheet['time'] }}</td>
                                <td style="padding: 10px; border: 1px solid #ddd;">{{ $sheet['work_type'] }}</td>
                                <td style="padding: 10px; border: 1px solid #ddd;">{{ $sheet['activity_type'] }}</td>
                                <td style="padding: 10px; border: 1px solid #ddd;">{{ $sheet['narration'] }}</td>
                                <td style="padding: 10px; border: 1px solid #ddd;">{{ $sheet['project_type'] }}</td>
                                <td style="padding: 10px; border: 1px solid #ddd;">{{ $sheet['project_type_status'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p style="margin-top: 30px; font-size: 16px;">Thanks,<br>The Team</p>
        </div>
    </div>
</body>
</html>
