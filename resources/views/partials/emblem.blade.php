{{-- Poster emblem: dome camera inside a ring of smart-home icons --}}
<div class="emblem {{ $class ?? '' }}" aria-hidden="true">
    <ul class="emblem__ring">
        <li style="--a:-72deg"><svg><use href="#i-thermo"/></svg></li>
        <li style="--a:-36deg"><svg><use href="#i-bulb"/></svg></li>
        <li style="--a:0deg"><svg><use href="#i-lock"/></svg></li>
        <li style="--a:36deg"><svg><use href="#i-shield"/></svg></li>
        <li style="--a:72deg"><svg><use href="#i-bolt"/></svg></li>
    </ul>
    <div class="emblem__label">SMART<br>HOME</div>
    <svg class="emblem__dome"><use href="#i-dome"/></svg>
</div>
