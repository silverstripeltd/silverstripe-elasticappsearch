# Contributing
<!-- START doctoc generated TOC please keep comment here to allow auto update -->
<!-- DON'T EDIT THIS SECTION, INSTEAD RE-RUN doctoc TO UPDATE -->
**Table of Contents**

- [Introduction](#introduction)
- [Pull requests welcome](#pull-requests-welcome)
- [Generating table of contents](#generating-table-of-contents)

<!-- END doctoc generated TOC please keep comment here to allow auto update -->

## Introduction
When you add new features, please include usage documentation in [usage.md](usage.md). If the use-case is quite advanced or not expected to be used by everyone, consider making a separate file and linking it instead.

## Pull requests welcome
Any and all pull requests are welcome. Please keep to [Silverstripe's standard coding conventions](https://docs.silverstripe.org/en/4/contributing/coding_conventions/).

## Generating table of contents
Table of contents used in documentation is built using [doctoc](https://github.com/thlorenz/doctoc).
```shell
npm install -g doctoc
doctoc docs/en
```
