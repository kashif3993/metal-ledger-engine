<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Metal Ledger — Live Demo</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f3f4f6;
            color: #111827;
        }
        .mono { font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; }
        .wrap { max-width: 860px; margin: 0 auto; padding: 32px 20px 60px; }
        .kicker { font-size: 11px; font-weight: 700; letter-spacing: 0.06em; color: #92722c; text-transform: uppercase; }
        h1 { font-size: 24px; margin: 6px 0 4px; }
        .sub { color: #6b7280; font-size: 14px; margin-bottom: 24px; }
        .card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px 22px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            margin-bottom: 18px;
        }
        .card h2 { font-size: 15px; margin: 0 0 6px; }
        .card p { font-size: 13px; color: #6b7280; margin: 0 0 14px; line-height: 1.5; }
        .buttons { display: flex; gap: 10px; flex-wrap: wrap; }
        button {
            font: inherit;
            font-weight: 700;
            font-size: 13px;
            padding: 9px 16px;
            border-radius: 7px;
            cursor: pointer;
            border: 1px solid transparent;
        }
        .btn-gold { background: #d4af37; border-color: #d4af37; color: #111; }
        .btn-gold:hover { background: #b5952f; }
        .btn-ghost { background: #ffffff; border-color: #d1d5db; color: #374151; }
        .btn-ghost:hover { background: #f3f4f6; }
        .result {
            background: #111827;
            color: #e5e7eb;
            border-radius: 10px;
            padding: 16px 18px;
            font-size: 13px;
            line-height: 1.7;
            white-space: pre-wrap;
        }
        .result .pass { color: #4ade80; }
        .result .fail { color: #f87171; }
        .result .warn { color: #fbbf24; }
        .badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 3px 9px;
            border-radius: 999px;
            background: #fdf6e3;
            color: #7a5c12;
            border: 1px solid #e8d18a;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="kicker">Metal Ledger &amp; Valuation Engine</div>
    <h1>Live Demo — Rate-Lock &amp; Karigar Reconciliation</h1>
    <div class="sub">Every button below calls the real backend code (<span class="mono">RateBookService</span>, <span class="mono">KarigarWorkOrderService</span>) — nothing shown here is hand-typed. Each run is wrapped in a database transaction that's rolled back immediately, so nothing is ever saved.</div>

    <div class="card">
        <span class="badge">Day 1 question — rate-lock</span>
        <h2>Lock a price, then finalize under three conditions</h2>
        <p>Locks a quote at Rs 22,000.00/g, then tries to finalize it under a different real-world condition for each button.</p>
        <div class="buttons">
            <form method="POST" action="{{ route('demo.run', 'normal') }}">
                @csrf
                <button type="submit" class="btn-gold">Normal — finalize in 5 min</button>
            </form>
            <form method="POST" action="{{ route('demo.run', 'expired') }}">
                @csrf
                <button type="submit" class="btn-ghost">Expired — finalize 20 min later</button>
            </form>
            <form method="POST" action="{{ route('demo.run', 'drifted') }}">
                @csrf
                <button type="submit" class="btn-ghost">Rate drifted — needs confirmation</button>
            </form>
        </div>
    </div>

    <div class="card">
        <span class="badge">Task 6 — karigar reconciliation (stretch)</span>
        <h2>Issue raw gold, settle a work order with a high cutting loss</h2>
        <p>Issues 40g to a karigar, then settles a work order where the cutting loss (11 ratti/tola) is above his owner-confirmed 4 ratti/tola fee.</p>
        <div class="buttons">
            <form method="POST" action="{{ route('demo.run', 'karigar') }}">
                @csrf
                <button type="submit" class="btn-gold">Run karigar settlement</button>
            </form>
        </div>
    </div>

    @if ($result)
        <div class="card">
            <h2 style="margin-bottom: 12px">Result — {{ $result['scenario'] }}</h2>
            <div class="result mono">@foreach ($result['lines'] as $line){{ $line }}
@endforeach</div>
        </div>
    @endif
</div>
</body>
</html>
