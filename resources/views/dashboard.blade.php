@extends('layout')

@section('content')
    <div class="grid">
        <div>
            <h2>Dashboard</h2>
            <p>Welcome to your secure portal, <strong>{{ Auth::user()->name }}</strong>!</p>
        </div>
        <div style="text-align: right;">
            <a href="/vitals/create" role="button">+ Record New Vitals</a>
        </div>
    </div>

    <hr>

    <h3>Your Recent Readings</h3>
    
    @if($vitals->isEmpty())
        <p>You haven't logged any data yet.</p>
    @else
        <table class="striped">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Heart Rate (BPM)</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                @foreach($vitals as $record)
                    <tr>
                        <td>{{ $record->created_at->format('M d, Y h:i A') }}</td>
                        <td><strong>{{ $record->heart_rate }}</strong></td>
                        <td>{{ $record->notes ?? 'N/A' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection