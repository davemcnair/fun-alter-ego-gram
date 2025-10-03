@php use App\Models\Token; @endphp
<div class="card" id="addWordCard">
    <form id="addWordForm" method="POST" action="#" aria-describedby="addWordErrors" class="add-word-form">
        @csrf
        <div class="form-field">
            <label for="wordInput" class="form-label">Word</label>
            <input id="wordInput" name="word" type="text" required class="form-input" />
        </div>
        <div class="form-field">
            <label for="tokenType" class="form-label">Token type</label>
            <select id="tokenType" name="token_type" class="form-input">
                @foreach(Token::DROPDOWN as $token)
                    <option value="{{ $token }}">{{ $token }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field">
            <label for="listType" class="form-label">List</label>
            <select id="listType" name="list_type" class="form-input">
                <option value="ok">ok</option>
                <option value="fun">fun</option>
            </select>
        </div>
        <div class="form-field">
            <button id="addWordBtn" type="submit" aria-busy="false">Add word</button>
        </div>
    </form>
    <div id="addWordErrors" class="muted form-errors" aria-live="polite" hidden></div>
</div>
