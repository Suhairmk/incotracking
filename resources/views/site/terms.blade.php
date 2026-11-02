@extends('site.layout')

@section('title', 'Terms and conditions — ' . config('app.name'))
@section('description', 'The terms that apply when using the ' . config('app.name') . ' field sales tracking application.')

@section('content')
    <div class="wrap legal">
        <h1>Terms and conditions</h1>
        <p class="updated">Last updated: {{ now()->format('F j, Y') }}</p>

        <div class="card">
            <p>
                These terms govern your use of {{ config('app.name') }} (the “App”) and the related
                website. By using the App you agree to them. If you do not agree, do not use the
                App and ask your administrator to disable your account.
            </p>
        </div>

        <h2>1. The service</h2>
        <p>
            The App records field work: attendance start and end times, selfies, vehicle meter
            photographs, location history while a work day is active, customer visits, and a shared
            directory of shops and dealers. It is provided to you by your employer as a workplace
            tool.
        </p>

        <h2>2. Accounts</h2>
        <ul>
            <li>Accounts are issued by your employer and are personal to you. Do not share your credentials.</li>
            <li>You are responsible for activity performed through your account.</li>
            <li>Tell your administrator promptly if you believe your account has been compromised.</li>
            <li>Your employer may suspend or delete your account at any time, in line with its own policies.</li>
        </ul>

        <h2>3. Acceptable use</h2>
        <p>You must not:</p>
        <ul>
            <li>record attendance, visits or meter readings that did not actually occur;</li>
            <li>submit photographs, locations or directory entries that are false, misleading or someone else's private information without permission;</li>
            <li>attempt to access records belonging to other employees or to the administration console without authorization;</li>
            <li>interfere with the service, bypass rate limits or security controls, or reverse engineer the App;</li>
            <li>use the App for any unlawful purpose.</li>
        </ul>

        <h2>4. Location and photographs</h2>
        <p>
            The App asks for camera and location permissions to function. Location is recorded only
            while a work day is active. Photographs you take become part of your employer's records.
            How they are used, stored and retained is described in our
            <a href="{{ route('site.privacy') }}">privacy policy</a> and your employer's own
            policies.
        </p>

        <h2>5. Accuracy of recorded data</h2>
        <p>
            The App relies on the device's GPS, clock and camera. Accuracy varies with weather,
            buildings, device hardware and signal conditions. Distances, durations and fuel figures
            are calculated from that input and are estimates. Report any discrepancy to your
            administrator rather than editing records yourself.
        </p>

        <h2>6. The shop and dealer directory</h2>
        <ul>
            <li>The directory is shared with everyone in your organisation.</li>
            <li>Your name is shown against entries you add.</li>
            <li>You must only add business information you are permitted to record, and must not include personal data about private individuals.</li>
            <li>Entries may be corrected or removed by an administrator.</li>
        </ul>

        <h2>7. Your employer's policies</h2>
        <p>
            These terms sit alongside your employer's employment, monitoring, acceptable-use and
            data-protection policies. Where those policies conflict with these terms, your
            employer's policies apply to your employment relationship.
        </p>

        <h2>8. Availability and changes</h2>
        <p>
            We may modify, suspend or discontinue any part of the App, including these terms. We
            will post the revised terms on this page with an updated date. Continued use after a
            change means you accept the revised terms.
        </p>

        <h2>9. Liability</h2>
        <p>
            To the maximum extent permitted by law, the App is provided “as is” without warranties
            of any kind, and neither the operator of this deployment nor your employer is liable
            for indirect, incidental or consequential loss arising from your use of the App. Nothing
            in these terms limits liability that cannot be limited by law.
        </p>

        <h2>10. Termination</h2>
        <p>
            You may stop using the App at any time by signing out. Your employer may terminate your
            access at any time. Sections that by their nature should survive termination — including
            records already lawfully retained — continue to apply.
        </p>

        <h2>11. Contact</h2>
        <p>
            Questions about these terms can be directed to your organisation's administrator, or to
            <a href="mailto:{{ config('mail.from.address') }}">{{ config('mail.from.address') }}</a>.
        </p>

        <p><a href="{{ route('site.landing') }}">&larr; Back to home</a></p>
    </div>
@endsection
