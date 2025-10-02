@php use App\Models\Token; @endphp
<div class="card" id="addWordCard">
    <form id="addWordForm" method="POST" action="#" aria-describedby="addWordErrors"
          style="display:grid; grid-template-columns: 1fr 1fr 1fr auto; gap:8px; align-items:end;">
        @csrf
        <div>
            <label for="tokenType" style="display:block; font-size:14px; margin-bottom:4px;">Token
                type</label>
            <select id="tokenType" name="token_type" class="input"
                    style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
                <option value="">Select token</option>
                @foreach(Token::NAMES as $token)
                    <option value="{{ $token }}">{{ $token }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="wordInput" style="display:block; font-size:14px; margin-bottom:4px;">Word</label>
            <input id="wordInput" name="word" type="text" required class="input"
                   style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;"/>
        </div>
        <div>
            <label for="listType" style="display:block; font-size:14px; margin-bottom:4px;">List</label>
            <select id="listType" name="list_type" class="input"
                    style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
                <option value="ok">ok</option>
                <option value="fun">fun</option>
            </select>
        </div>
        <div>
            <button id="addWordBtn" type="submit" aria-busy="false">Add word</button>
        </div>
    </form>
    <div id="addWordErrors" class="muted" aria-live="polite" hidden style="margin-top:6px;"></div>
</div>
