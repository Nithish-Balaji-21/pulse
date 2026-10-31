<h1>Coir Power</h1>
App to measure and report power utilization. 

<h2>Installation Instructions</h2>
<ol dir="auto">
    <li>Install Herd with php 8.3</li>
    <li>
        Install MySQL Server 8.x (Windows)
        <ul>
            <li>Download from: <a href="https://dev.mysql.com/downloads/installer/">https://dev.mysql.com/downloads/installer/</a></li>
            <li>Run MySQL Installer</li>
            <li>Select <strong>Developer Default</strong></li>
            <li>Set root password</li>
            <li>Finish installation</li>
            <li>Verify installation using:
                <code>mysql --version</code>
            </li>
        </ul>
    </li>
    <li>Install GitHub Desktop app and authorize with Git</li>
    <li>In GitHub Desktop, Clone from the main repository: <a href="https://github.com/coirpower/coirpower.git">https://github.com/coirpower/coirpower.git</a>.</li>
    <li>Open the cloned folder in Command Line.</li>
    <li>Install Composer dependencies using the <code>composer install</code> command. Note that we may need to run <code>composer update</code> first.</li>
    <li>Install npm dependencies using <code>npm install</code>. If it fails in VS code, run the command from command line.</li>
    <ul>
        <li>For EC2, you may need to first run<code>
        curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
        sudo apt install -y nodejs</code>
        </li>
        <li>This is also encouraged to run <code>sudo apt install -y build-essential</code></li>
        <li>Verify the installations using <code>
        node -v
        npm -v</code>
        </li>
        <li>Now run <code>npm install</code></li>
    </ul>
    <li>Build npm assets using <code>npm run build</code>.</li>
    <li>Create a <code>.env</code> file and copy the content from the <code>.env.dev.example</code> file into the <code>.env</code> file you created.</li>
    <li>Generate the application key by using <code>php artisan key:generate</code>and verify the key in the .env file.</li>
    <li>From /path/to/project/public location, run the command <code>ln -s /var/www/dev/storage/app/public/storage</code> to create symbolic link. necessary for file attachments.</li>
    <li>Run the app using <code> php artisan serve </code>.</li>
    <li>If artisan serve throws port error, fix the php.ini (Set variables_order to GPCS) </li>
    <li>Thats it!</li>
</ol>
<h2>Dev (Windows) Setup Help</h2>
<ol dir="auto">
    <li>Helpful VS Code extensions:</li>
        <ul>
            <li>GitHub Copilot (require authorization, VS code will automatically initiate)</li>
            <li>GitHub Copilot Chat</li>
            <li>Laravel Snippets</li>
            <li>Laravel Blade</li>
            <li>Laravel Blade Snippets</li>
        </ul>
</ol>
<h2>EC2 Install Instructions</h2>
<ol dir="auto">
    <li>Use AI to get commands to prepare the Ubuntu environment, "set up new AWS EC2 instance as a production-ready Laravel 12 environment using Nginx, MySQL 8.x, PHP 8.3+ and Supervisor — all within Free Tier–friendly limits."</li>
    <li>In github developer settings, create a personal access token (classic) (PAT) with "repo", "read:org" and "workflow" permissions. Copy the token as it won't be shown again.</li>
    <li>Lets go with GitHub CLI, follow the installation instructions at https://github.com/cli/cli/blob/trunk/docs/install_linux.md (yes, long command!)</li>
        <ul>
            <li>To authenticate to GitHub, run the following command from your terminal.<code>gh auth login</code></li>
            <li>Select GitHub.com</li>
            <li>Select HTTPS</li>
            <li>Select "Paste an Auth Token" and paste the PAT token</li>
            <li>Thats all! Once authenticated, the credentials (PAT) are securely stored and GitHub CLI will automatically handle authentication for subsequent sessions!</li>
        </ul>
    <li>From the <code>/var/www/dev</code> (or any directory or create one), run the command <code>git clone https://coirpower@github.com/coirpower/coirpower.git .</code> as ubuntu user. Use the PAT for the pwd. If an error is thrown "showing support for autentication was removed ...", just run the command again!</li>
    <li>Set correct permissions:<code>
    sudo chown -R ubuntu:ubuntu /var/www/dev 
    sudo chmod -R 755 /var/www/dev</code>
    </li>
    <li>Follow the Part I: Installation Instructions (from step 4)</li>
    <li>Project Folders and Files: Permissions and Owners</li>
        <ul>
            <li>All dirs to 755: <code>find /path/to/project -type d -exec chmod 755 {} \;</code></li>
            <li>All files to 644: <code>find /path/to/your/project -type f -exec chmod 644 {} \;</code></li>
            <li>storage dir: <code>chown -R www-data:www-data storage</code></li>
        </ul>
    <li>For new sites, create a file under "/etc/nginx/sites-available" (copy from prod or AI) and create a symbolic link at "/etc/nginx/sites-enabled"</li>
    <li>Reload nginx using <code>systemctl reload nginx</code></li>
</ol>

<h2>Tailwind CLI Instructions</h2>
<ol dir="auto">
    <li>
        CDN is used (in app.blade.php) in non-production environments for quick development. For production use the CLI method below. Install tailwind latest 4.x version.
    </li>
    <li>
        Then, run the following command to build the final CSS using Tailwind CLI:
        <pre><code>npm run tailwind-build</code></pre>
    </li>
    <li>
        This will watch <code>resources/css/app.css</code> for changes and automatically update <code>public/css/output.css</code>.
    </li>
    <li>
        <strong>Note:</strong> 
        <ul>
            <li><code>src/app.css</code> is your source file containing Tailwind's directives.</li>
            <li><code>src/output.css</code> is the compiled CSS file to include in your HTML.</li>
        </ul>
    </li>
</ol>

<h2>Technical Documentation - Local Setup & Access (MkDocs)</h2>
<ol dir="auto">
    <li>Install Python 3.8+:</li>
        <ul>
            <li>Download and install from: <a href="https://www.python.org/downloads/">https://www.python.org/downloads/</a>.</li>
            <li>During installation check this box: “Add Python to PATH”</li>
            <li><code>python3 --version</code>you see similar to this 3.xx.x continue</li>
            <li><code>pip --version</code></li>
        </ul>
    <li>Install MkDocs:</li>
        <ul>
            <li>Run <code>pip install mkdocs</code> from the project root folder (if MkDocs is not installed)</li>
            <li>Run <code>pip install mkdocs-material</code> (why tbd?) </li>
        </ul>
    <li>Run Documentation Locally</li>
    <ul>
        <li><code>mkdocs serve</code> (mac <code>python3 -m mkdocs serve</code>) from the root directory of the project</li>
    </ul>
    <li>Access the Documentation</li>
    <ul>
        <li>After running the command, you will see output like: Serving on<code>http://127.0.0.1:8000/</code></li>
        <li>Open your browser and visit: <code>http://127.0.0.1:8000/</code> or <code>http://localhost:8000</code></li> 
        Now you can see our Technical Documentaion
    </ul>
    <li>Stop the Server</li>
    <ul>
        <code>CTRL + C</code> to stop the local documentation server.
    </ul>
</ol>
