"""Create an audited public snapshot without private files or local Git history."""
from pathlib import Path
import json
import re
import shutil
import subprocess

ROOT = Path(__file__).resolve().parents[1]
DEST = ROOT / 'private' / 'publication' / 'Airbersih-KSM'
TEXT = {'.php', '.ini', '.json', '.lock', '.js', '.cjs', '.css', '.xml', '.md', '.yml', '.yaml', '.ps1', '.py', '.txt', '.example', '.service', '.html', '.svg'}


def selected_files():
    names = subprocess.check_output(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=ROOT).decode().split('\0')
    chosen = {}
    for name in names:
        if not name:
            continue
        path = Path(name)
        if name.startswith(('web/', 'scripts/', 'site/', 'deploy/')) or name == '.github/workflows/quality.yml':
            if name.startswith('web/') and ('vendor' in path.parts or 'node_modules' in path.parts or 'storage' in path.parts or '.vercel' in path.parts or '.env' in path.name and path.name != '.env.example'):
                continue
            if path.suffix in TEXT or path.name in {'.gitignore', '.gitattributes', '.editorconfig', '.vercelignore', 'artisan', '.htaccess', 'favicon.ico', 'robots.txt', '.nojekyll'}:
                chosen[name] = ROOT / name
    chosen.update({name: ROOT / name for name in ['AI_START_HERE.md', 'AGENTS.md', '.gitignore', '.gitattributes']})
    chosen['README.md'] = ROOT / 'docs/public/README.md'
    chosen['docs/PANDUAN_PENGURUS.md'] = ROOT / 'docs/PANDUAN_PENGURUS.md'
    for source in (ROOT / 'docs/public').glob('*.md'):
        chosen['docs/public/' + source.name] = source
        if source.name != 'README.md':
            chosen['docs/' + source.name] = source
    chosen['docs/PUBLICATION.md'] = ROOT / 'docs/PUBLICATION.md'
    return chosen


def audit(files):
    # Report file/line only. Never echo matched credential contents.
    patterns = [r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----', r'\bgh[pousr]_[A-Za-z0-9]{30,}', r'\bgithub_pat_[A-Za-z0-9_]{30,}', r'\bAKIA[A-Z0-9]{16}\b', r'postgres(?:ql)?://[^\s:/]+:[^\s@]+@', r'\beyJ[A-Za-z0-9_-]{15,}\.[A-Za-z0-9_-]{15,}\.[A-Za-z0-9_-]{15,}']
    findings = []
    for name, source in files.items():
        if not source.is_file() or source.is_symlink():
            raise RuntimeError('Missing file or symlink: ' + name)
        if source.suffix == '.ico':
            continue
        content = source.read_text(encoding='utf-8-sig')
        if name.endswith('prepare-publication.py'):
            continue
        for line, value in enumerate(content.splitlines(), 1):
            if any(re.search(pattern, value) for pattern in patterns):
                findings.append({'file': name, 'line': line, 'kind': 'credential-pattern'})
    if findings:
        raise RuntimeError(json.dumps(findings))


def main():
    files = selected_files()
    audit(files)
    DEST.mkdir(parents=True, exist_ok=True)
    generated = {'web/' + p + '/.gitignore' for p in ['storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache']}
    stale = [p.relative_to(DEST).as_posix() for p in DEST.rglob('*') if p.is_file() and '.git' not in p.relative_to(DEST).parts and p.relative_to(DEST).as_posix() not in files and p.relative_to(DEST).as_posix() not in generated]
    if stale:
        raise RuntimeError('Unexpected files in publication directory; inspect manually: ' + json.dumps(stale))
    for name, source in files.items():
        target = DEST / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source, target)
    # The public quality workflow needs runtime directories, but no runtime contents.
    for name in ['storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache']:
        path = DEST / 'web' / name
        path.mkdir(parents=True, exist_ok=True)
        (path / '.gitignore').write_text('*\n!.gitignore\n', encoding='utf-8')
    report = {'destination': str(DEST), 'files': len(files), 'credential_pattern_findings': 0, 'private_data_copied': False, 'history_copied': False}
    (ROOT / 'private/publication-audit.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
    print(json.dumps(report))


if __name__ == '__main__':
    main()
