<!DOCTYPE html>
<html>
<head><title>Add Record</title></head>
<body style="font-family: sans-serif; max-width: 400px; margin: 50px auto;">
    <h2>Submit Data</h2>

    <form method="POST" action="/vitals">
        @csrf 
        
        <div style="margin-bottom: 15px;">
            <label>Heart Rate (BPM):</label><br>
            <input type="number" name="heart_rate" required>
        </div>

        <div style="margin-bottom: 15px;">
            <label>Notes:</label><br>
            <textarea name="notes"></textarea>
        </div>

        <button type="submit">Save to Database</button>
    </form>
</body>
</html>