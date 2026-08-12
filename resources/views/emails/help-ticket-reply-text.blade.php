Hello {{ $ticket->email }},

Thank you for contacting NursingAI support.

Your request:
Subject: {{ $ticket->ticket_number ? $ticket->ticket_number.' — ' : '' }}{{ $ticket->subject }}

---
Support reply:
{{ $reply->message }}
---

If you still need help, reply to this email or submit another request from Settings → Help.

— NursingAI Support
