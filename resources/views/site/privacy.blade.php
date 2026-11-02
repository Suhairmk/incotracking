@extends('site.layout')

@section('title', 'Privacy policy — ' . config('app.name'))
@section('description', 'What the ' . config('app.name') . ' app collects, why it is collected, how long it is kept and who can see it.')

@section('content')
    <div class="wrap legal">
        <h1>Privacy policy</h1>
        <p class="updated">Last updated: {{ now()->format('F j, Y') }}</p>

        <div class="card">
            <p>
                This policy explains what {{ config('app.name') }} (the “App”) collects, why it is
                collected, how long it is kept and who can see it. The App is used by employees of
                an organisation (“your employer”) to record field work. Your employer is the
                controller of the data collected through the App; we operate the App on their
                behalf.
            </p>
        </div>

        <h2>1. Information we collect</h2>
        <ul>
            <li>
                <strong>Account details</strong> — your name, work email address, phone number,
                employee code and role.
            </li>
            <li>
                <strong>Attendance records</strong> — the time you start and end each work day,
                a selfie taken at the start and at the end of the day, a photograph of your
                vehicle's meter (odometer) at the start and at the end of the day, and the GPS
                position and accuracy at each of those moments.
            </li>
            <li>
                <strong>Location history</strong> — GPS position points recorded while a work day
                is active, used to build your route and calculate distance travelled. Location
                recording stops while your work day is paused and ends when you end the day.
            </li>
            <li>
                <strong>Customer visits</strong> — the customer or business name, visit outcome,
                contact person and number, any remarks you add, a photo taken as proof of the
                visit, and the GPS position and accuracy where it was recorded.
            </li>
            <li>
                <strong>Shop and dealer directory entries</strong> — outlet name, type, area name,
                address, phone, notes, the GPS position captured when you added it, and the date
                it was added.
            </li>
            <li>
                <strong>Device and usage information</strong> — a device identifier, push
                notification token, and activity log entries such as when a work day was started,
                paused, resumed or ended.
            </li>
        </ul>

        <h2>2. Why we collect it</h2>
        <ul>
            <li>To record and verify attendance, working hours and break times.</li>
            <li>To calculate distance travelled and, using the meter photographs, fuel consumed per vehicle and per employee.</li>
            <li>To evidence that customer visits took place at the stated time and place.</li>
            <li>To maintain a shared directory of shops and dealers for your territory.</li>
            <li>To let your employer's administrators review field activity across the team.</li>
            <li>To send you notifications about your work day and about records that are waiting to sync.</li>
        </ul>

        <h2>3. Location tracking</h2>
        <p>
            Background location is used <strong>only while a work day is active</strong>. Starting
            a work day requests location permission; ending it stops tracking, and pausing stops
            tracking until you resume. Location is not collected outside an active work day.
        </p>

        <h2>4. Photographs</h2>
        <p>
            Selfies, vehicle meter photographs and visit photographs are stored in private server
            storage. They are never published, never exposed through public links, and are served
            only to authenticated members of your organisation who are entitled to see them.
        </p>

        <h2>5. How long we keep it</h2>
        <p>
            Retention is governed by your employer's configuration. The defaults are:
        </p>
        <ul>
            <li>Location history visible in the app: the last {{ config('tracking.history_max_days') }} days.</li>
            <li>Raw location points archived after: {{ config('tracking.gps_retention_days') }} days.</li>
            <li>Photographs (attendance, meter and visit): {{ config('tracking.photo_retention_days') }} days.</li>
            <li>Attendance, visit and activity records: kept for as long as your employer requires for employment and audit purposes.</li>
        </ul>
        <p>
            After these periods, location points and photographs are deleted automatically by a
            scheduled purge job.
        </p>

        <h2>6. Who can see it</h2>
        <ul>
            <li>You can see your own records through the App.</li>
            <li>Administrators in your organisation can see the records of every employee, including photographs, routes and directory entries.</li>
            <li>Other executives can see the shop and dealer directory entries, with your name shown as the person who added them.</li>
            <li>We do not sell your personal data and do not share it with third parties for advertising.</li>
        </ul>

        <h2>7. Service providers</h2>
        <p>
            Data is hosted on infrastructure operated by our hosting and database providers, and
            push notifications may be delivered through Google's Firebase Cloud Messaging service.
            These providers process data only on our instructions.
        </p>

        <h2>8. Security</h2>
        <p>
            Data is transmitted over HTTPS, protected by access tokens, and access to photographs
            and records is authorised per user and per role. Storage is private rather than
            public.
        </p>

        <h2>9. Your choices and rights</h2>
        <ul>
            <li>You can refuse or revoke the location and camera permissions in your device settings. Doing so will prevent you from starting a work day or recording visits.</li>
            <li>You can ask your administrator to export or correct your records.</li>
            <li>You can ask your administrator to delete your account and associated records, subject to any retention your employer must keep.</li>
        </ul>

        <h2>10. Contact</h2>
        <p>
            For questions about this policy or about your data, contact your organisation's
            administrator. You can also reach the operator of this deployment at
            <a href="mailto:{{ config('mail.from.address') }}">{{ config('mail.from.address') }}</a>.
        </p>

        <p><a href="{{ route('site.landing') }}">&larr; Back to home</a></p>
    </div>
@endsection
