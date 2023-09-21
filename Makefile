pack:
	zip -r "miguel-$${CI_COMMIT_TAG:=dev}.zip" miguel --exclude ".DS_Store"
