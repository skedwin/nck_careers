<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $reportTitle }}</title>
    <style>
        @page { margin: 24px 28px; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            color: #1f2937;
            margin: 0;
        }
        .banner {
            background: #4b1e6d;
            color: #fff;
            text-align: center;
            padding: 10px 12px;
            font-size: 16px;
            font-weight: bold;
            letter-spacing: 0.04em;
        }
        .title {
            background: #f3eaf8;
            color: #351456;
            text-align: center;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: bold;
            border-bottom: 2px solid #c9a227;
        }
        .meta {
            background: #f7f2fa;
            color: #4b5563;
            text-align: center;
            padding: 6px 12px;
            font-size: 8px;
            font-style: italic;
            margin-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            background: #351456;
            color: #fff;
            font-size: 8px;
            text-align: left;
            padding: 6px 5px;
            border: 1px solid #4b1e6d;
        }
        td {
            padding: 5px;
            border: 1px solid #d1d5db;
            vertical-align: top;
            word-wrap: break-word;
        }
        tr:nth-child(even) td {
            background: #f8f4fb;
        }
        .empty {
            padding: 16px;
            text-align: center;
            color: #6b7280;
            font-style: italic;
        }
        .footer {
            margin-top: 12px;
            font-size: 7px;
            color: #6b7280;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="banner">NURSING COUNCIL OF KENYA</div>
    <div class="title">{{ strtoupper($reportTitle) }}</div>
    <div class="meta">
        Generated {{ \App\Support\NairobiDate::format(now()) }} (EAT)
        @if(!empty($reportSubtitle))
            · {{ $reportSubtitle }}
        @endif
        · Confidential
    </div>

    @if(empty($rows))
        <div class="empty">No records for this report.</div>
    @else
        <table>
            <thead>
                <tr>
                    @foreach($headers as $header)
                        <th>{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        @foreach($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">Nursing Council of Kenya — Careers · Confidential</div>
</body>
</html>
