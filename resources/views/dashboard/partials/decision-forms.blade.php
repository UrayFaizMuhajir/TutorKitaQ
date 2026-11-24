{{-- Form Setujui / Tolak (alasan wajib) untuk verifikasi. Parameter: $action (URL keputusan), $idSuffix. --}}
<div class="tutor-review-actions">
    <form method="post" action="{{ $action }}">@csrf<input type="hidden" name="decision" value="approve"><button class="button button-primary" type="submit">Setujui</button></form>
    <form method="post" action="{{ $action }}">@csrf<input type="hidden" name="decision" value="reject"><label class="sr-only" for="reject-reason-{{ $idSuffix }}">Alasan penolakan</label><input id="reject-reason-{{ $idSuffix }}" name="reason" placeholder="Alasan penolakan (wajib)" minlength="5" maxlength="2000" required><button class="button button-outline" type="submit">Tolak</button></form>
</div>
