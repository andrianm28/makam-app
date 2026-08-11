@php($state = strtolower((string) $state))
@switch($state)
    @case('idle') @include('components.document-vault.states.idle') @break
    @case('uploading') @include('components.document-vault.states.uploading', ['progress' => $progress ?? null, 'cancellable' => $cancellable ?? false, 'filename' => $filename ?? null, 'type' => $type ?? null, 'size' => $size ?? null]) @break
    @case('scanning') @case('quarantined') @include('components.document-vault.states.scanning', ['filename' => $filename ?? null, 'type' => $type ?? null, 'size' => $size ?? null]) @break
    @case('accepted') @include('components.document-vault.states.accepted') @break
    @case('rejected') @include('components.document-vault.states.rejected', ['reason' => $reason ?? 'The file could not be accepted.', 'filename' => $filename ?? null, 'type' => $type ?? null, 'size' => $size ?? null]) @break
@endswitch
