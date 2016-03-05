Hello {{ $user->name }},
<br />
<br />
Please click here to reset your password:
<a href="{{ $link = url('password/reset', $token).'?email='.urlencode($user->getEmailForPasswordReset()) }}"> {{ $link }} </a>.
<br />
If there are any problems with this link, please talk to Gareth.
<br />
<br />
Thanks,
<br />
Crockenhill Baptist Church
