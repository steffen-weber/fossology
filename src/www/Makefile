######################################################################
# Copyright (C) 2011 Hewlett-Packard Development Company, L.P.
#
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License
# version 2 as published by the Free Software Foundation.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License along
# with this program; if not, write to the Free Software Foundation, Inc.,
# 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
######################################################################

TOP = ../..
VARS = $(TOP)/Makefile.conf
include $(VARS)

DIRS = ui
TESTDIR = ui_tests

DIR_LOOP = @set -e; for dir in $(DIRS); do $(MAKE) -s -C $$dir $(1); done

all:
	$(call DIR_LOOP, )

test: all
	$(MAKE) -C $(TESTDIR) test

coverage: all
	$(MAKE) -C $(TESTDIR) coverage

install: all
	$(call DIR_LOOP,install)

uninstall:
	$(call DIR_LOOP,uninstall)

clean:
	$(call DIR_LOOP,clean)

.PHONY: all test coverage install uninstall clean
