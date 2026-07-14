@extends('layouts.kiosk')

@section('title', 'Choose your new password')

@section('content')
    <h1>Choose your new password</h1>
    @if (! empty($isReselection))
        <p class="alert-warning" role="status">
            The password you selected was accepted by Google but could not be used for your school computer account.
            Your first password may still work in Google. Please choose a different password so the same password can be applied to both Google and Windows.
        </p>
    @else
        <p class="muted">If technology staff approve your request, this will become your Google password.</p>
    @endif

    <form method="post" action="{{ route('kiosk.reset.password.store') }}">
        @csrf
        <label for="password">New password (at least {{ $minLength }} characters)</label>
        <input id="password" type="password" name="password" required autocomplete="new-password">

        <label for="password_confirmation">Confirm password</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">

        @if ($errors->any())
            <div class="alert-error" role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <button type="submit" class="btn btn-primary">Submit request</button>
    </form>
@endsection
