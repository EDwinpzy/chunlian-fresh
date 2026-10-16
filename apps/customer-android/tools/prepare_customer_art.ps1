$ErrorActionPreference = 'Stop'
$repositoryRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path
# Generate complete matching vector paths instead of clipped panel fragments.
python (Join-Path $repositoryRoot 'infrastructure\scripts\prepare-ui-icons.py')
if ($LASTEXITCODE -ne 0) { throw 'Icon generation failed' }
