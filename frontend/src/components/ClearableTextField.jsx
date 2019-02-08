import React, {Fragment, useRef, useState} from 'react';
import PropTypes from 'prop-types';
import { withStyles } from '@material-ui/core/styles';
import Paper from '@material-ui/core/Paper';
import InputBase from '@material-ui/core/InputBase';
import Divider from '@material-ui/core/Divider';
import IconButton from '@material-ui/core/IconButton';
import MenuIcon from '@material-ui/icons/Menu';
import SearchIcon from '@material-ui/icons/Search';
import ClearIcon from '@material-ui/icons/Clear';
import TextField from "@material-ui/core/TextField";
import InputAdornment from '@material-ui/core/InputAdornment';


const styles = {
    root: {
        padding: '2px 4px',
        display: 'flex',
        alignItems: 'center',
    },
    input: {
        marginLeft: 8,
        flex: 1,
    },
    iconButton: {
        padding: 10,
        marginRight: -12
    },
    divider: {
        width: 1,
        height: 28,
        margin: 4,
    },
};

function ClearableTextField(props) {
    const { classes, name, onClear, ...rest } = props;

    const inputEl = useRef(null);
    return (
       <Fragment>
           <div className={classes.root}>
            <TextField
                ref={inputEl}
                {...rest}
                name={name}
                InputProps={{
                    endAdornment: <InputAdornment position="end">
                        <IconButton className={classes.iconButton} onClick={() => onClear(name)}
                        >
                            <ClearIcon />
                        </IconButton>
                    </InputAdornment>,
                }}
            >
            </TextField>
           </div>
       </Fragment>
    );
}

ClearableTextField.propTypes = {
    classes: PropTypes.object.isRequired,
};

export default withStyles(styles)(ClearableTextField);