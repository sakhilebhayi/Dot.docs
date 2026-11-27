Hello {!! \App\Support\MailText::text($name, 80) !!},

Someone signed in to Dot.docs with your InfoDot account, and an account here already uses this email address. To connect the two, enter this code on the page that is open:

{{ $code }}

It works for {{ $minutes }} minutes and only once. Your Dot.docs password and your data are not changed by this.

If this was not you, ignore this message: nothing is connected unless the code is entered.
