<div id="cfcname">
    <strong>Working with CloudFlare over CNAMES</strong>
    <ul>
        <li>On your authoritative DNS, make sure that each activated record is pointing to its CloudFlare-specified CNAME.
            <ul>
                <li>For example: If <code>www.example.com</code>has been activated, then your authoritative DNS should set <code>www.example.com</code> as a <code>CNAME</code> to <code>www.example.com.cdn.cloudflare.net</code>
                </li>
            </ul>
        </li>
        <li>All <strong>proxy targets</strong> should resolve on the internet.
            <ul>
                <li>This may require modifying your authoritative DNS for the proxy target.</li>
                <li>For example, if the proxy target for <code>subdomain.example.com</code> is <code>cloudflare-resolve-to.example.com</code> then <code>cloudflare-resolve-to.example.com</code> should be created as an <code>A</code> or <code>CNAME</code> record and pointing to the desired server.
                </li>
            </ul>
        </li>
    </ul>
    <br>
</div>
