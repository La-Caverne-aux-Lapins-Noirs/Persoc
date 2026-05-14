#!/bin/sh
set -e

systemctl daemon-reload
systemctl enable --now persoc.service
systemctl status persoc.service --no-pager
