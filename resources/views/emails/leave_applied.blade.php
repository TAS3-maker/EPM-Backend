<!DOCTYPE html>
<html>
<head>
    <title>New Leave Request</title>
    <meta charset="UTF-8">
    <style>
        .container {
            background: #fff;
            max-width: 480px;
            margin: 40px auto;
            padding: 32px 24px;
        }
        .header {
            border-bottom: 1px solid #e2e8f0;
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
        .label {
            font-weight: bold;
            color: #2d3748;
        }
        .leave-type {
            font-weight: bold;
            color: #3182ce;
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
            <h2>New Leave Request</h2>
        </div>

        <div class="content">
            <p>Hello,</p>

            <p>
                <strong>{{ $leaveUser->name }}</strong>
                (Employee ID: {{ $leaveUser->employee_id ?? 'N/A' }})
                has submitted a leave request.
            </p>

            <p>
                <span class="label">Leave Type:</span>
                <span class="leave-type">{{ $leave->leave_type }}</span>
            </p>

            <p>
                <span class="label">From:</span>
                {{ \Carbon\Carbon::parse($leave->start_date)->format('d M Y') }}
                <br>
                <span class="label">To:</span>
                {{ \Carbon\Carbon::parse($leave->end_date)->format('d M Y') }}
            </p>

            @if($leave->leave_type === 'Short Leave')
                <p>
                    <span class="label">Time:</span> {{ $leave->hours }}
                </p>
            @endif

            @if($leave->leave_type === 'Half Day')
                <p>
                    <span class="label">Half Day:</span>
                    {{ ucfirst($leave->halfday_period) }}
                </p>
            @endif

            <p>
                <span class="label">Reason:</span>
                {{ $leave->reason }}
            </p>

            <p>
                <span class="label">Status:</span>
                {{ ucfirst($leave->status) }}
            </p>

            <p>
                Please login to the system to review and take action.
            </p>
        </div>

        <div class="footer">
            <strong>Thank you</strong><br>
            {{ config('app.name') }}
        </div>
    </div>
</body>
</html>
