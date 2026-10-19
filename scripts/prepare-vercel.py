"""Prepare a deployment-only package; private trust files never enter Git."""
from pathlib import Path
import importlib.util
import json
import shutil

ROOT = Path(__file__).resolve().parents[1]
DEST = ROOT / 'private/vercel/Airbersih-KSM'
spec = importlib.util.spec_from_file_location('publication', ROOT / 'scripts/prepare-publication.py')
publication = importlib.util.module_from_spec(spec)
spec.loader.exec_module(publication)
files = {name.removeprefix('web/'): source for name, source in publication.selected_files().items() if name.startswith('web/')}
publication.audit(files)
DEST.mkdir(parents=True, exist_ok=True)
for name, source in files.items():
    target = DEST / name
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(source, target)
shutil.copytree(ROOT / 'web/public/build', DEST / 'public/build', dirs_exist_ok=True)
(DEST / 'api/certs').mkdir(parents=True, exist_ok=True)
shutil.copyfile(ROOT / 'private/config/http-ca-bundle.pem', DEST / 'api/certs/http-ca.pem')
(DEST / '.vercel').mkdir(exist_ok=True)
shutil.copyfile(ROOT / 'web/.vercel/project.json', DEST / '.vercel/project.json')
for folder in ['storage/app/private', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache']:
    (DEST / folder).mkdir(parents=True, exist_ok=True)
print(json.dumps({'package': str(DEST), 'source_files': len(files), 'credentials_copied': False, 'runtime_public_trust_bundle_copied': True, 'docker': False}))
