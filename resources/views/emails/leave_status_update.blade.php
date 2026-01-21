<!DOCTYPE html>
<html>
<head>
    <title>Leave Status Update</title>
    <meta charset="UTF-8">
    <style>
        .container {
            background: #fff;
            max-width: 480px;
            margin: 40px auto;
            padding: 32px 24px;
        }
        .header {
            border-bottom: 1px solidrgb(139, 68, 68);
            margin-bottom: 24px;
            padding-bottom: 12px;
        }
        .header h2 {
            margin: 0;
            color: #2d3748;
            font-size: 22px;
        }
        .content p {
            color: #444;
            font-size: 16px;
            margin: 12px 0;
        }
        .status {
            font-weight: bold;
            color: {{ $leave->status == 'Approved' ? '#38a169' : '#e53e3e' }};
        }
        .footer {
            margin-top: 32px;
            font-size: 13px;
            color: #888;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Leave Status Update</h2>
        </div>
        <div class="content">
            <p>Hi <strong>{{ $user->name }}</strong>,</p>
            <p>
                Your leave request for <strong>{{ $leave->leave_type }}</strong>
                (from <strong>{{ \Carbon\Carbon::parse($leave->start_date)->format('d M Y') }}</strong>
                to <strong>{{ \Carbon\Carbon::parse($leave->end_date)->format('d M Y') }}</strong>)
                has been
                <span class="status">{{ $leave->status }}</span>
                by <strong>{{ $managerName }}</strong>.
            </p>
            <p><strong>Reason:</strong> {{ $leave->reason }}</p>
        </div>
        <div class="footer">
            <strong>Thank you</strong>
        </div>
    </div>
</body>
</html>
