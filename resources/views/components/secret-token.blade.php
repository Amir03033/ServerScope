@props(['token', 'title' => 'Bewaar dit token nu', 'id' => 'secret-token'])

<div class="secret-box" role="status">
    <strong>{{ $title }}</strong>
    <p class="small" style="margin:6px 0 0">
        Dit token wordt maar één keer getoond en is daarna niet meer op te vragen. Bewaar het veilig en deel het niet.
    </p>
    {{ $slot }}
    <div class="secret-row">
        <input id="{{ $id }}" class="input mono" type="password" readonly value="{{ $token }}" autocomplete="off" aria-label="{{ $title }}">
        <button class="button" type="button" onclick="const i=document.getElementById('{{ $id }}');i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'Tonen':'Verbergen'">Tonen</button>
        <button class="button" type="button" onclick="navigator.clipboard.writeText(document.getElementById('{{ $id }}').value).then(()=>this.textContent='Gekopieerd',()=>{const i=document.getElementById('{{ $id }}');i.type='text';i.select();})">Kopiëren</button>
    </div>
</div>
