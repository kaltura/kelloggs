import * as React from "react";
import Menu from '@material-ui/core/Menu';
import MenuItem from '@material-ui/core/MenuItem';
import Button from '@material-ui/core/Button';

export default class Commands extends React.PureComponent {
    state = {
        anchorEl: null,
    };

    constructor(props) {
        super(props);
    }

    handleClick = event => {
        this.setState({ anchorEl: event.currentTarget });
    };

    handleClose = () => {
        this.setState({ anchorEl: null });
    };

    render() {
        const { anchorEl } = this.state;

        return <React.Fragment>
            <Button onClick={this.handleClick}>
                Commands
            </Button>
            <Menu
                id="simple-menu1"
                anchorEl={anchorEl}
                open={Boolean(anchorEl)}
                onClose={this.handleClose} >
                {
                    this.props.commands.map(cmd => {
                        return <MenuItem onClick={ ()=> { this.handleClose(); alert(cmd.data)}}>{cmd.label}</MenuItem>
                    })
                }
            </Menu>
        </React.Fragment>
    }

}