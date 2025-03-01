Table post-thread-user
===========

Thread related data per user

Fields
------

| Field        | Description                                                                                             | Type               | Null | Key | Default             | Extra |
| ------------ | ------------------------------------------------------------------------------------------------------- | ------------------ | ---- | --- | ------------------- | ----- |
| uri-id       | Id of the item-uri table entry that contains the item uri                                               | int unsigned       | NO   | PRI | NULL                |       |
| owner-id     | Item owner                                                                                              | int unsigned       | NO   |     | 0                   |       |
| author-id    | Item author                                                                                             | int unsigned       | NO   |     | 0                   |       |
| causer-id    | Link to the contact table with uid=0 of the contact that caused the item creation                       | int unsigned       | YES  |     | NULL                |       |
| network      |                                                                                                         | char(4)            | NO   |     |                     |       |
| created      |                                                                                                         | datetime           | NO   |     | 0001-01-01 00:00:00 |       |
| received     |                                                                                                         | datetime           | NO   |     | 0001-01-01 00:00:00 |       |
| changed      | Date that something in the conversation changed, indicating clients should fetch the conversation again | datetime           | NO   |     | 0001-01-01 00:00:00 |       |
| commented    |                                                                                                         | datetime           | NO   |     | 0001-01-01 00:00:00 |       |
| uid          | Owner id which owns this copy of the item                                                               | mediumint unsigned | NO   | PRI | 0                   |       |
| pinned       | The thread is pinned on the profile page                                                                | boolean            | NO   |     | 0                   |       |
| starred      |                                                                                                         | boolean            | NO   |     | 0                   |       |
| ignored      | Ignore updates for this thread                                                                          | boolean            | NO   |     | 0                   |       |
| wall         | This item was posted to the wall of uid                                                                 | boolean            | NO   |     | 0                   |       |
| mention      |                                                                                                         | boolean            | NO   |     | 0                   |       |
| pubmail      |                                                                                                         | boolean            | NO   |     | 0                   |       |
| forum_mode   |                                                                                                         | tinyint unsigned   | NO   |     | 0                   |       |
| contact-id   | contact.id                                                                                              | int unsigned       | NO   |     | 0                   |       |
| unseen       | post has not been seen                                                                                  | boolean            | NO   |     | 1                   |       |
| hidden       | Marker to hide the post from the user                                                                   | boolean            | NO   |     | 0                   |       |
| origin       | item originated at this site                                                                            | boolean            | NO   |     | 0                   |       |
| psid         | ID of the permission set of this post                                                                   | int unsigned       | YES  |     | NULL                |       |
| post-user-id | Id of the post-user table                                                                               | int unsigned       | YES  |     | NULL                |       |

Indexes
------------

| Name          | Fields         |
| ------------- | -------------- |
| PRIMARY       | uid, uri-id    |
| uri-id        | uri-id         |
| owner-id      | owner-id       |
| author-id     | author-id      |
| causer-id     | causer-id      |
| uid           | uid            |
| contact-id    | contact-id     |
| psid          | psid           |
| post-user-id  | post-user-id   |
| commented     | commented      |
| uid_received  | uid, received  |
| uid_pinned    | uid, pinned    |
| uid_commented | uid, commented |
| uid_starred   | uid, starred   |
| uid_mention   | uid, mention   |

Foreign Keys
------------

| Field | Target Table | Target Field |
|-------|--------------|--------------|
| uri-id | [item-uri](help/database/db_item-uri) | id |
| owner-id | [contact](help/database/db_contact) | id |
| author-id | [contact](help/database/db_contact) | id |
| causer-id | [contact](help/database/db_contact) | id |
| uid | [user](help/database/db_user) | uid |
| contact-id | [contact](help/database/db_contact) | id |
| psid | [permissionset](help/database/db_permissionset) | id |
| post-user-id | [post-user](help/database/db_post-user) | id |

Return to [database documentation](help/database)
