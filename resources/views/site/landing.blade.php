@extends('site.layout')

@section('title', config('app.name') . ' — Field sales tracking for distributed teams')
@section('description', 'Attendance selfies, vehicle meter readings, GPS routes, customer visits and a shared shop directory — built for field sales teams that work away from a desk.')

@section('content')
    <section class="hero">
        <div class="wrap">
            <span class="eyebrow">Field sales tracking</span>
            <h1>Know where the workday actually happened.</h1>
            <p class="lead">
                {{ config('app.name') }} records where a work day started and ended, what the
                vehicle meter read, which customers were visited, and where every dealer and shop
                in your territory sits — with proof attached to each entry.
            </p>
            <div class="actions">
                <a class="btn btn-primary" href="#how">See how it works</a>
                <a class="btn btn-ghost" href="{{ route('site.privacy') }}">How we handle your data</a>
            </div>
        </div>
    </section>

    <section class="section" id="features">
        <div class="wrap">
            <h2>Everything a field day produces, in one record</h2>
            <p class="intro">
                Built for Android phones carried all day: captures work offline, syncs when there is
                signal, and never leaves a manager guessing about a gap.
            </p>
            <div class="grid">
                <article class="card">
                    <span class="icon" aria-hidden="true">✓</span>
                    <h3>Attendance with proof</h3>
                    <p>
                        A selfie and a GPS fix open and close every work day, so start and end times
                        are backed by evidence rather than a tap.
                    </p>
                </article>
                <article class="card">
                    <span class="icon" aria-hidden="true">⛽</span>
                    <h3>Vehicle meter readings</h3>
                    <p>
                        An odometer photo is captured at the start and end of each day, giving you
                        the raw numbers needed to calculate fuel consumption per employee.
                    </p>
                </article>
                <article class="card">
                    <span class="icon" aria-hidden="true">⏸</span>
                    <h3>Honest break tracking</h3>
                    <p>
                        Pausing stops GPS and stops the clock. Breaks are subtracted automatically,
                        so worked time is genuinely worked time.
                    </p>
                </article>
                <article class="card">
                    <span class="icon" aria-hidden="true">📍</span>
                    <h3>Routes and distance</h3>
                    <p>
                        Batched location points build the day's route, with server-side quality
                        filtering and a distance total managers can trust.
                    </p>
                </article>
                <article class="card">
                    <span class="icon" aria-hidden="true">🤝</span>
                    <h3>Customer visits</h3>
                    <p>
                        Each visit stores the customer, outcome, contact details, a shopfront photo
                        and the exact position where it was recorded.
                    </p>
                </article>
                <article class="card">
                    <span class="icon" aria-hidden="true">📱</span>
                    <h3>Works without signal</h3>
                    <p>
                        Captures are stored on the device first and uploaded automatically later, so
                        a dead zone never costs a day of records.
                    </p>
                </article>
            </div>
        </div>
    </section>

    <section class="section" id="directory">
        <div class="wrap">
            <h2>One shared directory of shops and dealers</h2>
            <p class="intro">
                Every executive adds the outlets they find. Everybody else benefits from it
                immediately.
            </p>
            <div class="grid">
                <article class="card">
                    <span class="icon" aria-hidden="true">＋</span>
                    <h3>Add an outlet with its position</h3>
                    <p>
                        Name, type, area and a GPS fix captured on the spot — attribution stays with
                        the person who found it.
                    </p>
                </article>
                <article class="card">
                    <span class="icon" aria-hidden="true">🔎</span>
                    <h3>Search the way you think</h3>
                    <p>
                        One search box matches the shop name or the area it sits in, narrow to
                        dealers only, or restrict the list to what you added yourself.
                    </p>
                </article>
                <article class="card">
                    <span class="icon" aria-hidden="true">🧭</span>
                    <h3>Nearby search, sorted by distance</h3>
                    <p>
                        Hand the app your current position and it returns the closest outlets first,
                        within a radius you choose.
                    </p>
                </article>
                <article class="card">
                    <span class="icon" aria-hidden="true">🗺</span>
                    <h3>Jump straight into Google Maps</h3>
                    <p>
                        One tap on an outlet hands the coordinates to the Google Maps app for
                        directions — no copying, no transcription errors.
                    </p>
                </article>
            </div>
        </div>
    </section>

    <section class="section" id="how">
        <div class="wrap">
            <h2>How a work day runs</h2>
            <p class="intro">Four steps, in order, on the phone in the field.</p>
            <div class="steps">
                <article class="step">
                    <h3>Start the day</h3>
                    <p>
                        Confirm, wait for a GPS fix, take a start selfie and photograph the vehicle
                        meter. Tracking begins.
                    </p>
                </article>
                <article class="step">
                    <h3>Work the territory</h3>
                    <p>
                        Record customer visits with photos, look up nearby outlets and navigate with
                        Google Maps. Pause for breaks whenever you like.
                    </p>
                </article>
                <article class="step">
                    <h3>End the day</h3>
                    <p>
                        Take a final selfie and the closing meter reading. The day's distance, hours
                        and break time are calculated for you.
                    </p>
                </article>
                <article class="step">
                    <h3>Review centrally</h3>
                    <p>
                        Administrators see every session, selfie, meter reading, route, visit and
                        shop entry across the whole team.
                    </p>
                </article>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="wrap">
            <h2>Your data, explained plainly</h2>
            <p class="intro">
                Location history, attendance photos and meter readings are collected for your
                employer's legitimate business purposes only. Read exactly what is stored and for
                how long.
            </p>
            <div class="actions">
                <a class="btn btn-primary" href="{{ route('site.privacy') }}">Privacy policy</a>
                <a class="btn btn-ghost" href="{{ route('site.terms') }}">Terms &amp; conditions</a>
            </div>
        </div>
    </section>
@endsection
