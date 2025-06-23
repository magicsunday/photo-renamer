##!/usr/bin/env bash
#if [ ! -z "$(which renamer)" ]; then
#    echo "renamer is already installed"
#    exit 0
#fi
#
#echo "Installing renamer..."
#
#if [ ! -d "$HOME/.local/bin" ]; then
#    mkdir -p "$HOME/.local/bin"
#    echo "✅ local bin directory created"
#fi
#
#if [[ $PATH == *".local/bin"* ]]; then
#    echo "✅ local bin directory already in PATH"
#else
#    echo "export PATH=$HOME/.local/bin:$PATH" >> "$HOME/.bashrc"
#    echo "✅ local bin directory added to PATH"
#    source "$HOME/.bashrc"
#fi
#
#curl -sL https://renamer.sobol.nr/renamer -o "renamer" --output-dir "$HOME/.local/bin"
#chmod +x "$HOME/.local/bin/renamer"
#echo "✅ renamer installed"
#
#echo "renamer is installed. Run 'renamer' to see the available commands."
