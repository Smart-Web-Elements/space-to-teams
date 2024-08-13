# Space2Teams

Space2Teams is a tool designed to export channels and messages from JetBrains Space into JSON files and import them back to Microsoft Teams.

## Project Description

Space2Teams enables seamless migration of communication data between JetBrains Space and Microsoft Teams. It specifically caters to users who want to export their channels and messages from JetBrains Space and import them to Microsoft Teams with minimal hassle.

## Installation

To install and use Space2Teams, you need to have PHP 8.3 or higher and Composer 2 installed on your system. There are no special installation steps required.

## Permissions

Before using Space2Teams, ensure that you have the following permissions set up:

### JetBrains Space Permissions:
- View messages
- View channel info
- View channel participants
- View all external users
- View member profiles
- View member profile basic info

### Microsoft Teams Permissions:
- ChannelMember.ReadWrite.All
- ChannelSettings.ReadWrite.All
- Group.ReadWrite.All
- Team.ReadBasic.All
- TeamMember.ReadWrite.All
- Teamwork.Migrate.All
- User.Read.All

## Usage Instructions

1. **Setup Environment Variables:**
    - Copy the `.env.template` file to a new file named `.env`.
    - Fill in the values required in the `.env` file.

2. **Export Channels and Messages:**
    - Execute the following command to export channels and messages from JetBrains Space:
      ```sh
      php export
      ```

3. **Import Messages:**
    - Execute the following command to import the messages into Microsoft Teams:
      ```sh
      php import
      ```

**Note:** You may need to edit the export and import scripts to customize channel skipping or mapping rules according to your specific requirements.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for more details.